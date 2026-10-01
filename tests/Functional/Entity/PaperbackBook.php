<?php

declare(strict_types=1);

namespace ACSEO\TypesenseBundle\Tests\Functional\Entity;

/**
 * Stands in for a Doctrine inheritance subclass (e.g. Single/Class Table Inheritance), where the
 * collection is configured with the parent entity FQCN but instances report the subclass via
 * get_class().
 */
class PaperbackBook extends Book
{
}
