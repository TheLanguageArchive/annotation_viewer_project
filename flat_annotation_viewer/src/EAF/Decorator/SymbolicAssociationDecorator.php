<?php
namespace Drupal\flat_annotation_viewer\EAF\Decorator;

/**
 * SymbolicAssociationDecorator divides a tier's annotations across the begin and end timeslots
 *
 * - All ref annotations
 * - References alignable annotation
 * - resolve time from top of tree
 *
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class SymbolicAssociationDecorator
{
    /**
     * Divide annotation within tier
     *
     * @param array $tier
     *
     * @return void
     */
    public static function decorate(array &$tier)
    {
        foreach ($tier['annotations'] as &$annotation) {

            $ref = $annotation['referenced_annotation'];
            $annotation['custom_start'] = $ref['custom_start'] ?? $ref['start'];
            $annotation['custom_end']   = $ref['custom_end'] ?? $ref['end'];
        }
    }
}
