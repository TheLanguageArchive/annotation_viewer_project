<?php
namespace Drupal\flat_annotation_viewer\EAF;

use Drupal\flat_annotation_viewer\EAF\Resolver\MediaResolver;
use Drupal\flat_annotation_viewer\EAF\Parser\MetadataParser;
use Drupal\flat_annotation_viewer\EAF\Parser\HeaderParser;
use Drupal\flat_annotation_viewer\EAF\Parser\MediaParser;
use Drupal\flat_annotation_viewer\EAF\Parser\TimeslotParser;
use Drupal\flat_annotation_viewer\EAF\Parser\LinguisticTypeParser;
use Drupal\flat_annotation_viewer\EAF\Parser\TiersParser;
use Drupal\flat_annotation_viewer\EAF\Decorator\TiersDecorator;
use Drupal\flat_annotation_viewer\EAF\Decorator\SymbolicAssociationDecorator;
use Drupal\flat_annotation_viewer\EAF\Decorator\SymbolicSubdivisionDecorator;
use Drupal\flat_annotation_viewer\EAF\Decorator\TimeSubdivisionDecorator;
use Drupal\flat_annotation_viewer\EAF\Sorter\TierAnnotationsSorter;

/**
 * Parser
 *
 * @author  Ibrahim Abdullah <ibrahim.abdullah@mpi.nl>
 * @package TLA EAF Parser
 */
use SimpleXMLElement;

class Parser
{
    /** @var string */
    const ANNOTATION_TYPE_ALIGNABLE            = 'alignable';

    /** @var string */
    const ANNOTATION_TYPE_REF                  = 'ref';

    /** @var string */
    const LINGUISTIC_TYPE_TOP_LEVEL            = 'toplevel';

    /** @var string */
    const LINGUISTIC_TYPE_SYMBOLIC_ASSOCIATION = 'Symbolic_Association';

    /** @var string */
    const LINGUISTIC_TYPE_TIME_SUBDIVISION     = 'Time_Subdivision';

    /** @var string */
    const LINGUISTIC_TYPE_SYMBOLIC_SUBDIVISION = 'Symbolic_Subdivision';

    /** @var string */
    const LINGUISTIC_TYPE_INCLUDED_IN          = 'Included_In';

    /**
     * @var SimpleXMLElement
     */
    private $xml;

    /**
     * @var MediaResolver
     */
    private $mediaResolver;

    /**
     * Constructor
     *
     * @param SimpleXMLElement $xml
     * @param MediaResolver    $mediaResolver
     */
    public function __construct(SimpleXMLElement $xml, MediaResolver $mediaResolver)
    {
        $this->xml           = $xml;
        $this->mediaResolver = $mediaResolver;
    }

    /**
     * Parsing EAF
     *
     * @return array
     */
    public function parse()
    {
        $parsed = [

            'metadata' => MetadataParser::parse($this->xml),
            'header'   => HeaderParser::parse($this->xml->HEADER),
            'tiers'    => [],
        ];

        $parsed['header']['media'] = MediaParser::parse($this->xml->HEADER->MEDIA_DESCRIPTOR, $this->mediaResolver);

        $timeslots       = TimeslotParser::parse($this->xml->TIME_ORDER->TIME_SLOT);
        $linguisticTypes = LinguisticTypeParser::parse($this->xml->LINGUISTIC_TYPE);
        $parsed['tiers'] = TiersParser::parse($this->xml->TIER);

        TiersDecorator::decorate($parsed['tiers'], $timeslots, $linguisticTypes);

        // Pass 1: Time Subdivision (Virtual times for parents)
        foreach ($parsed['tiers'] as &$tier) {
            if ($tier['linguistic_type'] === Parser::LINGUISTIC_TYPE_TIME_SUBDIVISION) {
                TimeSubdivisionDecorator::decorate($tier);
            }
        }

        // Pass 2: Associations and Subdivisions (Inherit from parents)
        foreach ($parsed['tiers'] as &$tier) {
            if ($tier['linguistic_type'] === Parser::LINGUISTIC_TYPE_SYMBOLIC_SUBDIVISION) {
                TierAnnotationsSorter::sort($tier);
                SymbolicSubdivisionDecorator::decorate($tier);
            }

            if ($tier['linguistic_type'] === Parser::LINGUISTIC_TYPE_SYMBOLIC_ASSOCIATION) {
                SymbolicAssociationDecorator::decorate($tier);
            }
        }

        return $parsed;
    }
}
