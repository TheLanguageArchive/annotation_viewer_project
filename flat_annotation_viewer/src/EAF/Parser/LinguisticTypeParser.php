<?php
namespace Drupal\flat_annotation_viewer\EAF\Parser;

use Drupal\flat_annotation_viewer\EAF\Parser;
use SimpleXMLElement;

/**
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class LinguisticTypeParser
{
    /**
     * Parsing linguistic types
     *
     * @param SimpleXMLElement $items
     *
     * @return array
     */
    public static function parse(SimpleXMLElement $items): array
    {
        $linguisticTypes = [];

        foreach ($items as $item) {

            $attributes = $item->attributes();
            $id         = (string)$attributes['LINGUISTIC_TYPE_ID'];

            if (!isset($attributes['CONSTRAINTS'])) {
                $linguisticTypes[$id] = Parser::LINGUISTIC_TYPE_TOP_LEVEL;
            } else {
                $linguisticTypes[$id] = (string)$attributes['CONSTRAINTS'];
            }
        }

        return $linguisticTypes;
    }
}
