<?php
namespace Drupal\flat_annotation_viewer\EAF\Parser;

use SimpleXMLElement;

/**
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class TimeslotParser
{
    /**
     * Parsing timeslots
     *
     * @param SimpleXMLElement $items
     *
     * @return array
     */
    public static function parse(SimpleXMLElement $items): array
    {
        $timeslots = [];

        foreach ($items as $item) {

            $attributes     = $item->attributes();
            $id             = (string)$attributes['TIME_SLOT_ID'];
            $time           = isset($attributes['TIME_VALUE']) ? (int)$attributes['TIME_VALUE'] : null;

            $timeslots[$id] = $time;
        }

        return $timeslots;
    }
}
