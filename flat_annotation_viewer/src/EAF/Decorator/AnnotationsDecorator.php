<?php
namespace Drupal\flat_annotation_viewer\EAF\Decorator;

use Drupal\flat_annotation_viewer\EAF\Parser;

/**
 * decorating annotations
 *
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class AnnotationsDecorator
{
    /**
     * Decorating annotations
     *
     * @param array $annotations
     * @param array $timeslots
     *
     * @return void
     */
    public static function decorate(array &$annotations, array &$timeslots)
    {
        foreach ($annotations as &$annotation) {

            $annotation['custom_start'] = null;
            $annotation['custom_end']   = null;

            if ($annotation['type'] === Parser::ANNOTATION_TYPE_REF) {

                $id = self::findReference($annotations, $annotation['ref']);
                $annotation['referenced_annotation'] = &$annotations[$id] ?? null;
            }

            if ($annotation['type'] === Parser::ANNOTATION_TYPE_ALIGNABLE) {

                $annotation['start'] = $timeslots[$annotation['start']] ?? null;
                $annotation['end']   = $timeslots[$annotation['end']] ?? null;
            }
        }
    }

    /**
     * Finding the immediate referenced annotation or null.
     *
     * Returns the direct parent so that Symbolic_Subdivision / Symbolic_Association
     * decorators inherit timing from the immediate parent (which may itself be a
     * subdivided REF_ANNOTATION with custom_start/custom_end), not from the root
     * alignable ancestor.
     *
     * @param array  $annotations
     * @param string $ref
     *
     * @return string|null
     */
    public static function findReference(&$annotations, $ref)
    {
        if (trim($ref) === '' || !isset($annotations[$ref])) {
            return null;
        }

        return $annotations[$ref]['id'];
    }
}
