<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;

// Eng begrenzt: nur die Entity-Mappings von @ORM-Annotationen auf #[ORM\...]-Attribute.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src/Entity'])
    ->withSets([DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES]);
