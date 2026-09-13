<?php
namespace Drupal\flat_annotation_viewer\EAF\Parser;

use SimpleXMLElement;

/**
 * Properties parser
 *
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class PropertiesParser
{
    /**
     * Parsing properties
     *
     * @param SimpleXMLElement $items
     *
     * @return array
     */
    public static function parse(SimpleXMLElement $items): array
    {
        $properties = [];

        foreach ($items as $item) {

            $properties[] = [

                'name' => (string)$item->attributes()['NAME'],
                'value' => (string)$item,
            ];
        }

        return $properties;
    }
}
