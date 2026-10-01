<?php

declare(strict_types=1);

namespace ACSEO\TypesenseBundle\EventListener;

use ACSEO\TypesenseBundle\Manager\CollectionManager;
use ACSEO\TypesenseBundle\Manager\DocumentManager;
use ACSEO\TypesenseBundle\Transformer\DoctrineToTypesenseTransformer;
use Doctrine\Persistence\Proxy;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class TypesenseIndexer
{
    private $collectionManager;
    private $transformer;
    private $managedClassNames;
    private DocumentManager $documentManager;

    private $objetsIdThatCanBeDeletedByObjectHash = [];
    private $documentsToIndex                     = [];
    private $documentsToUpdate                    = [];
    private $documentsToDelete                    = [];

    public function __construct(
        CollectionManager $collectionManager,
        DocumentManager $documentManager,
        DoctrineToTypesenseTransformer $transformer
    ) {
        $this->collectionManager = $collectionManager;
        $this->documentManager   = $documentManager;
        $this->transformer       = $transformer;

        $this->managedClassNames  = $this->collectionManager->getManagedClassNames();
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToIndex[] = [$collection, $data];
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $collectionDefinitionKey = $this->getCollectionKey($entity);
        $collectionConfig        = $this->collectionManager->getCollectionDefinitions()[$collectionDefinitionKey];

        $this->checkPrimaryKeyExists($collectionConfig);

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToUpdate[] = [$collection, $data['id'], $data];
    }

    private function checkPrimaryKeyExists($collectionConfig)
    {
        foreach ($collectionConfig['fields'] as $config) {
            if ($config['type'] === 'primary') {
                return;
            }
        }

        throw new \Exception(sprintf('Primary key info have not been found for Typesense collection %s', $collectionConfig['typesense_name']));
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $data = $this->transformer->convert($entity);

        $this->objetsIdThatCanBeDeletedByObjectHash[spl_object_hash($entity)] = $data['id'];
    }

    public function postRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        $entityHash = spl_object_hash($entity);

        if (!isset($this->objetsIdThatCanBeDeletedByObjectHash[$entityHash])) {
            return;
        }

        $collection = $this->getCollectionName($entity);

        $this->documentsToDelete[] = [$collection, $this->objetsIdThatCanBeDeletedByObjectHash[$entityHash]];
    }

    public function postFlush()
    {
        $this->indexDocuments();
        $this->deleteDocuments();

        $this->resetDocuments();
    }

    private function indexDocuments()
    {
        foreach ($this->documentsToIndex as $documentToIndex) {
            $this->documentManager->index(...$documentToIndex);
        }
        foreach ($this->documentsToUpdate as $documentToUpdate) {
            $this->documentManager->index($documentToUpdate[0], $documentToUpdate[2]);
        }
    }

    private function deleteDocuments()
    {
        foreach ($this->documentsToDelete as $documentToDelete) {
            $this->documentManager->delete(...$documentToDelete);
        }
    }

    private function resetDocuments()
    {
        $this->documentsToIndex  = [];
        $this->documentsToUpdate = [];
        $this->documentsToDelete = [];
    }

    private function entityIsNotManaged($entity)
    {
        return $this->resolveManagedClassName($entity) === null;
    }

    private function getCollectionName($entity)
    {
        $managedClassName = $this->resolveManagedClassName($entity);

        if ($managedClassName === null) {
            return false;
        }

        return array_search($managedClassName, $this->managedClassNames, true);
    }

    private function getCollectionKey($entity)
    {
        $managedClassName = $this->resolveManagedClassName($entity);

        if ($managedClassName === null) {
            return null;
        }

        foreach ($this->collectionManager->getCollectionDefinitions() as $key => $def) {
            if ($def['entity'] === $managedClassName) {
                return $key;
            }
        }

        return null;
    }

    // See : https://github.com/acseo/TypesenseBundle/pull/91
    // Allow Doctrine inheritance subclasses to be recognized as their managed parent entity, the
    // same way DoctrineToTypesenseTransformer::convert() already does.
    private function resolveManagedClassName($entity): ?string
    {
        $entityClassname = $entity instanceof Proxy ? $entity::class : get_class($entity);

        foreach (array_values($this->managedClassNames) as $managedClassName) {
            if (is_a($entityClassname, $managedClassName, true)) {
                return $managedClassName;
            }
        }

        return null;
    }
}
