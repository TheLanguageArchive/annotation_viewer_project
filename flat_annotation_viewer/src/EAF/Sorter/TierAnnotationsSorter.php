<?php
namespace Drupal\flat_annotation_viewer\EAF\Sorter;

use Drupal\flat_annotation_viewer\EAF\Parser;

/**
 * Orders symbolic annotations by their PREVIOUS_ANNOTATION links.
 *
 * @author Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
class TierAnnotationsSorter
{
    public static function sort(array &$tier)
    {
        $sorted = [];
        foreach ($tier['annotations'] as $id => $annotation) {
            $chain = [];
            $seen = [];
            $cursor = $id;
            // Iterative traversal also handles long chains without recursion.
            while (!array_key_exists($cursor, $sorted)) {
                if (isset($seen[$cursor])) {
                    throw new \UnexpectedValueException('Cyclic PREVIOUS_ANNOTATION chain.');
                }
                $seen[$cursor] = TRUE;
                $chain[] = $cursor;
                $current = $tier['annotations'][$cursor];
                $previous = $current['previous'] ?? NULL;
                if ($current['type'] !== Parser::ANNOTATION_TYPE_REF || $previous === NULL) break;
                if (!isset($tier['annotations'][$previous]) ||
                    $tier['annotations'][$previous]['type'] !== Parser::ANNOTATION_TYPE_REF ||
                    $tier['annotations'][$previous]['ref'] !== $current['ref']) {
                    throw new \UnexpectedValueException('Invalid PREVIOUS_ANNOTATION reference.');
                }
                $cursor = $previous;
            }
            foreach (array_reverse($chain) as $key) {
                // Preserve references held by the timing decorators.
                $sorted[$key] = &$tier['annotations'][$key];
            }
        }
        $tier['annotations'] = $sorted;
    }
}
