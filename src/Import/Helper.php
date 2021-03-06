<?php
/**
 * Helper Klasse für den Import von Einsatzberichten
 */
namespace abrain\Einsatzverwaltung\Import;

use abrain\Einsatzverwaltung\Data;
use abrain\Einsatzverwaltung\Exceptions\ImportException;
use abrain\Einsatzverwaltung\Exceptions\ImportPreparationException;
use abrain\Einsatzverwaltung\Import\Sources\AbstractSource;
use abrain\Einsatzverwaltung\Model\IncidentReport;
use abrain\Einsatzverwaltung\ReportNumberController;
use abrain\Einsatzverwaltung\Utilities;
use DateTime;

/**
 * Verschiedene Funktionen für den Import von Einsatzberichten
 */
class Helper
{

    /*
     * @var Utilities
     */
    private $_utilities;

    /*
     * @var Data
     */
    private $_data;

    /*
     * @var array
     */
    public $metaFields;

    /*
     * @var array
     */
    public $postFields;

    /*
     * @var array
     */
    public $imageFields;

    /*
     * @var array
     */
    public $taxonomies;

    /**
     * Helper constructor.
     *
     * @param Utilities $utilities
     * @param Data      $data
     */
    public function __construct(Utilities $utilities, Data $data)
    {
        $this->_utilities = $utilities;
        $this->_data = $data;
    }

    /**
     * Gibt ein Auswahlfeld zur Zuordnung der Felder in Einsatzverwaltung aus
     *
     * @param array $args Argumenten-Array
     * {
     *     @type string $name              Name des Dropdownfelds im Formular
     *     @type string $selected          Wert der ausgewählten Option
     *     @type array  $unmatchableFields Felder, die nicht als Importziel
     *                                     auswählbar sein sollen
     * }
     *
     * @return null
     */
    private function _dropdownEigeneFelder($args)
    {
        $defaults = array(
            'name' => null,
            'selected' => '-',
            'unmatchableFields' => array(),
        );
        $parsedArgs = wp_parse_args($args, $defaults);

        if (null === $parsedArgs['name'] || empty($parsedArgs['name'])) {
            _doing_it_wrong(__FUNCTION__, 'Name darf nicht null oder leer sein', '');
        }

        $fields = IncidentReport::getFields();

        // Felder, die automatisch beschrieben werden, nicht zur Auswahl stellen
        foreach ($parsedArgs['unmatchableFields'] as $ownField) {
            unset($fields[$ownField]);
        }

        // Sortieren und ausgeben
        uasort(
            $fields,
            function ($field1, $field2) {
                return strcmp($field1['label'], $field2['label']);
            }
        );
        $string = '<select name="' . $parsedArgs['name'] . '">';
        /* @noinspection HtmlUnknownAttribute */
        $string .= sprintf(
            '<option value="-" %s>%s</option>',
            selected($parsedArgs['selected'], '-', false),
            'nicht importieren'
        );
        foreach ($fields as $slug => $fieldProperties) {
            /* @noinspection HtmlUnknownAttribute */
            $string .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($slug),
                selected($parsedArgs['selected'], $slug, false),
                esc_html($fieldProperties['label'])
            );
        }
        $string .= '</select>';

        echo $string;
    }

    /**
     * MapEntryToInsertArgs
     *
     * @param array $mapping
     * @param array $sourceEntry
     * @param array $insertArgs
     *
     * @return null
     *
     * @throws ImportPreparationException
     */
    public function mapEntryToInsertArgs($mapping, $sourceEntry, &$insertArgs)
    {
        foreach ($mapping as $sourceField => $ownField) {
            if (empty($ownField) || !is_string($ownField)) {
                $this->_utilities->printError("Feld '$ownField' ung&uuml;ltig");
                continue;
            }

            $sourceValue = trim($sourceEntry[$sourceField]);
            if (array_key_exists($ownField, $this->metaFields)) {
                // Wert gehört in ein Metafeld
                $insertArgs['meta_input'][$ownField] = $sourceValue;
            } elseif (array_key_exists($ownField, $this->taxonomies)) {
                // Wert gehört zu einer Taxonomie
                if (empty($sourceValue)) {
                    // Leere Terms überspringen
                    continue;
                }

                $insertArgs['tax_input'][$ownField] = $this->getTaxInputString(
                    $ownField, $sourceValue
                );
            } elseif (array_key_exists($ownField, $this->postFields)) {
                // Wert gehört direkt zum Post
                $insertArgs[$ownField] = $sourceValue;
            } elseif (array_key_exists($ownField, $this->imageFields)) {
                // Wert gehört in ein Metafeld
                $insertArgs['image'][$ownField] = $sourceValue;
            } elseif ($ownField == '-') {
                $this->_utilities->printWarning(
                    "Feld '$sourceField' nicht zugeordnet"
                );
            } else {
                $this->_utilities->printError("Feld '$ownField' unbekannt");
            }
        }
    }

    /**
     * Bereitet eine kommaseparierte Auflistung von Terms einer
     * bestimmten Taxonomie so, dass sie beim Anlegen eines
     * Einsatzberichts für die gegebene Taxonomie
     * als tax_input verwendet werden kann.
     *
     * @param string $taxonomy
     * @param string $terms
     *
     * @return string
     *
     * @throws ImportPreparationException
     */
    public function getTaxInputString($taxonomy, $terms)
    {
        if (is_taxonomy_hierarchical($taxonomy) === false) {
            // Termnamen können direkt verwendet werden
            return $terms;
        }

        // Bei hierarchischen Taxonomien muss ID anstelle Namen verwendet werden
        $termIds = array();

        $termNames = explode(',', $terms);
        foreach ($termNames as $termName) {
            $termIds[] = $this->getTermId($termName, $taxonomy);
        }

        return implode(',', $termIds);
    }

    /**
     * Bestimmt die ID eines Terms einer hierarchischen Taxonomie.
     * Existiert dieser noch nicht, wird er angelegt.
     *
     * @param string $termName
     * @param string $taxonomy
     *
     * @return int
     *
     * @throws ImportPreparationException
     */
    public function getTermId($termName, $taxonomy)
    {
        if (is_taxonomy_hierarchical($taxonomy) === false) {
            throw new ImportPreparationException(
                "Die Taxonomie $taxonomy ist nicht hierarchisch!"
            );
        }

        $termName = trim($termName);
        $term = get_term_by('name', $termName, $taxonomy);

        if ($term !== false) {
            // Term existiert bereits, ID verwenden
            return $term->term_id;
        }

        // Term existiert in dieser Taxonomie noch nicht, neu anlegen
        $newterm = wp_insert_term($termName, $taxonomy);

        if (is_wp_error($newterm)) {
            throw new ImportPreparationException(
                sprintf(
                    "Konnte %s '%s' nicht anlegen: %s",
                    $this->taxonomies[$taxonomy]['label'],
                    $termName, $newterm->get_error_message()
                )
            );
        }

        // Anlegen erfolgreich, zurückgegebene ID verwenden
        return $newterm['term_id'];
    }

    /**
     * Importiert Einsätze aus der wp-einsatz-Tabelle
     *
     * @param AbstractSource $source       Quelle
     * @param array          $mapping      Zuordnung zwischen zu importieren
     *                                     Feldern und denen der Einsatzverwaltung
     * @param ImportStatus   $importStatus Status
     *
     * @return null
     *
     * @throws ImportException
     * @throws ImportPreparationException
     */
    public function import($source, $mapping, $importStatus)
    {
        $preparedInsertArgs = array();
        $yearsAffected = array();

        // Den Import vorbereiten, um möglichst alle Fehler vorher abzufangen
        $this->prepareImport($source, $mapping, $preparedInsertArgs, $yearsAffected);

        $importStatus->totalSteps = count($preparedInsertArgs);
        $importStatus->displayMessage('Daten eingelesen, starte den Import...');

        // Den tatsächlichen Import starten
        $this->runImport(
            $preparedInsertArgs, $source, $yearsAffected, $importStatus
        );
    }

    /**
     * PrepareArgsForInsertPost
     *
     * @param array    $insertArgs
     * @param string   $dateTimeFormat
     * @param string   $postStatus
     * @param DateTime $alarmzeit
     *
     * @return null
     *
     * @throws ImportPreparationException
     */
    public function prepareArgsForInsertPost(
        &$insertArgs,
        $dateTimeFormat,
        $postStatus,
        $alarmzeit
    ) {
        // Datum des Einsatzes prüfen
        if (false === $alarmzeit) {
            throw new ImportPreparationException(
                sprintf(
                    'Die Alarmzeit %s konnte mit dem angegebenen Format %s nicht eingelesen werden',
                    esc_html($insertArgs['post_date']),
                    esc_html($dateTimeFormat)
                )
            );
        }

        /*
         * Solange der Einsatzbericht ein Entwurf ist,
         * soll kein Datum gesetzt werden (vgl. wp_update_post()).
         */
        if ($postStatus === 'draft') {
            // Wird bis zur Veröffentlichung in Postmeta zwischengespeichert.
            $insertArgs['meta_input']['_einsatz_timeofalerting'] = date_format(
                $alarmzeit, 'Y-m-d H:i:s'
            );
            unset($insertArgs['post_date']);
            unset($insertArgs['post_date_gmt']);
        } else {
            $insertArgs['post_date'] = $alarmzeit->format('Y-m-d H:i:s');
            $insertArgs['post_date_gmt'] = get_gmt_from_date(
                $insertArgs['post_date']
            );
        }

        // Einsatzende korrekt formatieren
        if (array_key_exists('einsatz_einsatzende', $insertArgs['meta_input'])
            && !empty($insertArgs['meta_input']['einsatz_einsatzende'])
        ) {
            $endDate = DateTime::createFromFormat(
                $dateTimeFormat,
                $insertArgs['meta_input']['einsatz_einsatzende']
            );
            if (false === $endDate) {
                throw new ImportPreparationException(
                    sprintf(
                        'Das Einsatzende %s konnte mit dem angegebenen Format %s nicht eingelesen werden',
                        esc_html($insertArgs['meta_input']['einsatz_einsatzende']),
                        esc_html($dateTimeFormat)
                    )
                );
            }

            $insertArgs['meta_input']['einsatz_einsatzende'] = $endDate->format(
                'Y-m-d H:i'
            );
        }

        $insertArgs['post_type'] = 'einsatz';
        $insertArgs['post_status'] = $postStatus;

        // Titel sicherstellen
        if (!array_key_exists('post_title', $insertArgs)) {
            $insertArgs['post_title'] = 'Einsatz';
        }
        $insertArgs['post_title'] = wp_strip_all_tags($insertArgs['post_title']);
        if (empty($insertArgs['post_title'])) {
            $insertArgs['post_title'] = 'Einsatz';
        }

        // sicherstellen, dass boolsche Werte als 0 oder 1 dargestellt werden
        $boolAnnotations = array(
            'einsatz_special',
            'einsatz_fehlalarm',
            'einsatz_hasimages',
        );
        foreach ($boolAnnotations as $metaKey) {
            $insertArgs['meta_input'][$metaKey] = $this->sanitizeBooleanValues(
                @$insertArgs['meta_input'][$metaKey]
            );
        }

        // Bilder als Anhänge erstellen oder in Anhängen suchen
        if (array_key_exists('gallery', $insertArgs['image'])
            && !empty($insertArgs['image']['gallery'])
        ) {
            $images = $this->parseImageArrayFromString(
                $insertArgs['image']['gallery']
            );
            if (is_array($images) && count($images) > 0) {
                // Hier kann alternativ auch ein  Gallery Shortcode erzeugt werden
                // $shortcode = ws_sprintf('[gallery ids="%"]',$images);
                // oder für die angehängten Bilder:
                // $shortcode = '[gallery columns="3" link="file"]';
                // Shortcode zu post content hinzufügen
                // $insertArgs['post_content'] .= $shortcode;
                // => IMO Besser: In Template erledigen...
                // Setze Bilder-Flag
                $insertArgs['meta_input']['einsatz_hasimages'] = 1;
                // Attachment IDs Zwischenspeichern
                $insertArgs['image']['_gallery'] = $images;
            }
        }
    }

    /**
     * Versucht Bilder aus String parsen und in Attachment zu wandeln
     * gibt im Erfolgsfall Array aus Attachment IDs zurück
     *
     * @param string $imgArrString Kommaseparierter String aus Bildpfaden
     *
     * @return array
     */
    public function parseImageArrayFromString($imgArrString)
    {
        $imgIds = array();
        if ($imgArrString) {
            $images = explode(',', $imgArrString);
            foreach ($images as $img) {
                $attachment_id = $this->getAttachmentIdByUrl(trim($img));
                if ($attachment_id) {
                    $imgIds[] = $attachment_id;
                } else {
                    throw new ImportPreparationException(
                        sprintf(
                            'Das Bild "%s" konnte nicht eingelesen werden, Datei wurde nicht gefunden oder ist nicht lesbar (Berechtigung?).',
                            esc_html(trim($img))
                        )
                    );
                }

            }
        }
        return $imgIds;
    }

    /**
     * Suche nach Attachment in Datenbank über Dateiname/URL.
     *
     * Prüfe zunächst, ob in Datenbank mit rechtem Pfadteil enthalten
     * Wenn gefunden, gebe ID zurück.
     * Wenn nicht, lege Attachment neu an.
     *
     * @param string $url Pfad zum Bild (ex: /wp-content/uploads/2013/05/image.jpg)
     *
     * @return int $attachment Gibt attachment ID zurück
     */
    public function getAttachmentIdByUrl($url)
    {
        // TODO: Absoluten Pfad entfernen (Security?!)
        /*
         * Suche in DB nach attachment GUID mit
         * einem Teiltreffer (rechter Pfadanteil)
         * Example: uploads/2013/05/test-image.jpg
         */
        global $wpdb;

        $attachment = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}posts WHERE post_type='attachment' AND guid RLIKE %s LIMIT 1;",
                $url
            )
        );

        if (count($attachment) > 0) {
            // Treffer in Datenbank zurückgeben
            return $attachment[0];
        }
        // Importiere Bild und gebe neue attachmentID oder null zurück
        return $this->addAttachment($url);
    }

    /**
     * Fügt Anhang (Bild) anhand einer URI in Mediathek
     *
     * @param string $url Pfad zu Bild,
     *                    relativ unter WP_CONTENT_DIR => /wp-content/uploads/...
     *
     * @return int|WP_Error AttachmentId oder WP_Error
     */
    public function addAttachment($url)
    {
        $wp_upload_dir = wp_upload_dir();
        $fileUri = $wp_upload_dir['baseurl'] . '/' . $url;
        $fileName = basename($url);
        $filePath = $wp_upload_dir['basedir'] . DIRECTORY_SEPARATOR . $url;
        if (!is_readable($filePath)) {
            // Datei existiert nicht oder ist nicht lesbar!
            return null;
        }

        // TODO: Dateityp auf Bild prüfen
        $wp_filetype = wp_check_filetype($fileName, null);

        // Metadaten anlegen
        $attachment = array(
            'guid' => $fileUri, // image link
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($fileName),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        /* Anhang anlegen */
        $attachmentId = wp_insert_attachment($attachment, $url);
        if ($attachmentId && $attachmentId > 0) {
            if (!function_exists('wp_crop_image')) {
                include_once ABSPATH . 'wp-admin/includes/image.php';
            }

            /* Metadaten/Thumbnails erstellen */
            $get_size = getimagesize($filePath); // get image width and height
            $attachmentMetadata = array(
                'ID' => wp_generate_attachment_metadata($attachmentId, $fileUri),
                'width' => $get_size[0],
                'height' => $get_size[1],
            );

            /* Metadaten mit Anhang verknüpfen */
            wp_update_attachment_metadata($attachmentId, $attachmentMetadata);

            return $attachmentId;
        }
        return null;
    }

    /**
     * Stellt sicher, dass boolsche Werte durch 0 und 1 dargestellt werden
     *
     * @param string $value boolscher Wert, kann null oder leer sein
     *
     * @return string
     */
    public function sanitizeBooleanValues($value)
    {
        if (empty($value)) {
            return '0';
        }

        return (
            in_array(strtolower($value), array('1', 'ja')) ? '1' : '0'
        );
    }

    /**
     * PrepareImport
     *
     * @param AbstractSource $source
     * @param array          $mapping
     * @param array          $preparedInsertArgs
     * @param array          $yearsAffected
     *
     * @return null
     *
     * @throws ImportPreparationException
     */
    public function prepareImport(
        $source,
        $mapping,
        &$preparedInsertArgs,
        &$yearsAffected
    ) {
        $sourceEntries = $source->getEntries(array_keys($mapping));
        if (empty($sourceEntries)) {
            throw new ImportPreparationException(
                'Die Importquelle lieferte keine Ergebnisse. Entweder sind dort keine Eins&auml;tze gespeichert oder es gab ein Problem bei der Abfrage.'
            );
        }

        $dateFormat = $source->getDateFormat();
        $timeFormat = $source->getTimeFormat();
        if (!empty($dateFormat) && !empty($timeFormat)) {
            $dateTimeFormat = $dateFormat . ' ' . $timeFormat;
        }
        if (empty($dateTimeFormat)) {
            $dateTimeFormat = 'Y-m-d H:i';
        }

        // Der Veröffentlichungsstatus der importierten Berichte
        $postStatus = $source->isPublishReports() ? 'publish' : 'draft';

        foreach ($sourceEntries as $sourceEntry) {
            $insertArgs = array();
            $insertArgs['post_content'] = '';
            $insertArgs['tax_input'] = array();
            $insertArgs['meta_input'] = array();

            $this->mapEntryToInsertArgs($mapping, $sourceEntry, $insertArgs);
            $alarmzeit = DateTime::createFromFormat(
                $dateTimeFormat, $insertArgs['post_date']
            );
            $this->prepareArgsForInsertPost(
                $insertArgs,
                $dateTimeFormat,
                $postStatus,
                $alarmzeit
            );
            /*
             * Prüfen ob Eintrag schon existiert
             * => kostet Performance bietet aber konsistente Daten!
             */
            if (array_key_exists(
                'einsatz_incidentNumber', $insertArgs['meta_input']
            )
                && !empty($insertArgs['meta_input']['einsatz_incidentNumber'])
            ) {
                $args = array(
                    'post_type' => 'einsatz',
                    'posts_per_page' => 1,
                    'meta_query' => array(
                        array(
                            'key' => 'einsatz_incidentNumber',
                            'value' => $insertArgs['meta_input']['einsatz_incidentNumber'],
                            'compare' => '=',
                        ),
                    ),
                );
                // Suche nach Einsatz mit Einsatznummer
                $posts = query_posts($args);

                $post_exists = (count($posts) > 0);
            } else {
                $post_exists = false;
            }

            if (!$post_exists) {
                $preparedInsertArgs[] = $insertArgs;
                $yearsAffected[$alarmzeit->format('Y')] = 1;
            }
        }
    }

    /**
     * Gibt das Formular für die Zuordnung zwischen zu
     * importieren Feldern und denen von Einsatzverwaltung aus
     *
     * @param AbstractSource $source
     * @param array          $args
     * {
     *     @type array  $mapping           Zuordnung von zu importieren 
     *                                     Feldern auf Einsatzverwaltungsfelder
     *     @type array  $next_action       Array der nächsten Action
     *     @type string $nonce_action      Wert der Nonce
     *     @type string $action_value      Wert der action-Variable
     *     @type string submit_button_text Beschriftung für den Button 
     *                                     unter dem Formular
     * }
     *
     * @return null
     */
    public function renderMatchForm($source, $args)
    {
        /* Erkannte Feldnamen in CSV (Feldname = Label) */
        $mapping_fields = array_combine(
            array_column(IncidentReport::getFields(), 'label'),
            array_keys(IncidentReport::getFields())
        );

        /* Temporär: benutze eigenes Zusatzmapping, kann ggf. raus */
        $mapping_add = array(
            'nr' => 'einsatz_incidentNumber',
            'title' => 'post_title',
            'besonderer einsatz' => 'einsatz_special',
            'stichwort' => 'einsatzart',
            'fehlalarm' => 'einsatz_fehlalarm',
            'created',
            'datum',
            'uhrzeit',
            'einsatzbeginn' => 'post_date',
            'einsatzende' => 'einsatz_einsatzende',
            'ort' => 'einsatz_einsatzort',
            'fahrzeuge' => 'fahrzeug',
            'extern' => 'exteinsatzmittel',
            'mannschaft' => 'einsatz_mannschaft',
            'text' => 'post_content',
        );
        /* Ergänge um eigenes temporäres Mapping (siehe oben), kann ggf. raus */
        $mapping_fields = array_merge(
            // Auch Feld-Schlüssel suchen
            array_combine(
                array_keys(IncidentReport::getFields()),
                array_keys(IncidentReport::getFields())
            ),
            // Feld-Label
            $mapping_fields,
            $mapping_add
        );

        $defaults = array(
            'mapping' => $mapping_fields,
            'next_action' => null,
            'nonce_action' => '',
            'action_value' => '',
            'submit_button_text' => 'Import starten',
        );

        $parsedArgs = wp_parse_args($args, $defaults);
        $fields = $source->getFields();

        $unmatchableFields = $source->getUnmatchableFields();
        if (ReportNumberController::isAutoIncidentNumbers()) {
            $this->_utilities->printInfo(
                'Einsatznummern können nur importiert werden, wenn die automatische Verwaltung deaktiviert ist.'
            );
            $unmatchableFields[] = 'einsatz_incidentNumber';
        }

        echo '<form method="post">';
        wp_nonce_field($parsedArgs['nonce_action']);
        echo '<input type="hidden" name="aktion" value="' . $parsedArgs['action_value'] . '" />';
        echo '<table class="evw_match_fields"><tr><th>';
        printf('Feld in %s', $source->getName());
        echo '</th><th>' . 'Feld in Einsatzverwaltung' . '</th></tr><tbody>';
        foreach ($fields as $field) {
            echo '<tr><td><strong>' . $field . '</strong></td><td>';
            if (array_key_exists($field, $source->getAutoMatchFields())) {
                echo 'wird automatisch zugeordnet';
            } elseif (in_array($field, $source->getProblematicFields())) {
                $this->_utilities->printWarning(
                    sprintf('Probleme mit Feld %s, siehe Analyse', $field)
                );
            } else {
                $selected = '-';
                if (!empty($parsedArgs['mapping'])
                    && array_key_exists(
                        strtolower($field),
                        array_change_key_case($parsedArgs['mapping'], CASE_LOWER)
                    )
                    && !empty($parsedArgs['mapping'][$field])
                ) {
                    $selected = $parsedArgs['mapping'][$field];
                }

                $this->_dropdownEigeneFelder(
                    array(
                        'name' => $source->getInputName($field),
                        'selected' => $selected,
                        'unmatchableFields' => $unmatchableFields,
                    )
                );
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($parsedArgs['next_action'])) {
            $source->echoExtraFormFields($parsedArgs['next_action']);
        }
        submit_button($parsedArgs['submit_button_text']);
        echo '</form>';
    }

    /**
     * RunImport
     *
     * @param array          $preparedInsertArgs
     * @param AbstractSource $source
     * @param array          $yearsAffected
     * @param ImportStatus   $importStatus
     *
     * @return null
     *
     * @throws ImportException
     */
    public function runImport(
        $preparedInsertArgs,
        $source,
        $yearsAffected,
        $importStatus
    ) {
        /* 
        * Für die Dauer des Imports sollen die laufenden Nummern 
        * nicht aktuell gehalten werden, da dies die Performance
        * stark beeinträchtigt
        */
        if ($source->isPublishReports()) {
            $this->_data->pauseAutoSequenceNumbers();
        }

        foreach ($preparedInsertArgs as $insertArgs) {
            // Neuen Beitrag anlegen
            $postId = wp_insert_post($insertArgs, true);
            if (is_wp_error($postId)) {
                throw new ImportException(
                    'Konnte Einsatz nicht importieren: ' . 
                    $postId->get_error_message()
                );
            }
            // Update attached images
            if (array_key_exists('image', $insertArgs)
                && array_key_exists('_gallery', $insertArgs['image'])
                && is_array($insertArgs['image']['_gallery'])
                && count($insertArgs['image']['_gallery']) > 0
            ) {
                foreach ($insertArgs['image']['_gallery'] as $imgId) {
                    if (is_numeric($imgId) && $imgId > 0) {
                        // Attach attachmentID to Post
                        wp_update_post(
                            array(
                                'ID' => $imgId,
                                'post_parent' => $postId,
                            )
                        );
                    }
                }
                // Erstes Bild als Thumbnail setzen
                set_post_thumbnail($postId, $insertArgs['image']['_gallery'][0]);
            }
            $importStatus->importSuccesss($postId);
        }

        if ($source->isPublishReports()) {
            /* 
            * Die automatische Aktualisierung der laufenden Nummern 
            * wird wieder aufgenommen
            */
            $this->_data->resumeAutoSequenceNumbers();
            foreach (array_keys($yearsAffected) as $year) {
                $importStatus->displayMessage(
                    sprintf(
                        'Aktualisiere laufende Nummern für das Jahr %d...',
                        $year
                    )
                );
                $this->_data->updateSequenceNumbers(strval($year));
            }
        }
    }

    /**
     * Prüft, ob das Mapping stimmig ist und gibt Warnungen oder Fehlermeldungen aus
     *
     * @param array          $mapping Das zu prüfende Mapping
     * @param AbstractSource $source  Quelle
     *
     * @return bool True bei bestandener Prüfung, false bei Unstimmigkeiten
     */
    public function validateMapping($mapping, $source)
    {
        $valid = true;

        // Pflichtfelder prüfen
        if (!in_array('post_date', $mapping)) {
            $this->_utilities->printError(
                'Pflichtfeld Alarmzeit wurde nicht zugeordnet'
            );
            $valid = false;
        }

        $unmatchableFields = $source->getUnmatchableFields();
        $autoMatchFields = $source->getAutoMatchFields();
        if (ReportNumberController::isAutoIncidentNumbers()) {
            $unmatchableFields[] = 'einsatz_incidentNumber';
        }
        foreach ($unmatchableFields as $unmatchableField) {
            if (in_array($unmatchableField, $mapping) 
                && !in_array($unmatchableField, $autoMatchFields)
            ) {
                $this->utilities->printError(
                    sprintf(
                        'Feld %s kann nicht f&uuml;r ein zu importierendes Feld als Ziel angegeben werden',
                        esc_html($unmatchableField)
                    )
                );
                $valid = false;
            }
        }

        // Mehrfache Zuweisungen prüfen
        foreach (array_count_values($mapping) as $ownField => $count) {
            if ($count > 1) {
                $this->_utilities->printError(
                    sprintf(
                        'Feld %s kann nicht f&uuml;r mehr als ein zu importierendes Feld als Ziel angegeben werden',
                        IncidentReport::getFieldLabel($ownField)
                    )
                );
                $valid = false;
            }
        }

        return $valid;
    }
}
