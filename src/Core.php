<?php
namespace abrain\Einsatzverwaltung;

use abrain\Einsatzverwaltung\Util\Formatter;
use abrain\Einsatzverwaltung\Widgets\RecentIncidents;
use abrain\Einsatzverwaltung\Widgets\RecentIncidentsFormatted;

/**
 * Grundlegende Funktionen
 */
class Core
{
    const VERSION = '1.4.3';
    const DB_VERSION = 30;

   /**
    * Statische Variable, um die aktuelle (einzige!) Instanz dieser Klasse zu halten
    * @var Core
    */
    private static $instance = null;

    public static $pluginFile;
    public static $pluginBasename;
    public static $pluginDir;
    public static $pluginUrl;
    public static $scriptUrl;
    public static $styleUrl;

    /**
     * @var Data
     */
    private $data;
    
    /**
     * @var Options
     */
    public $options;
    
    /**
     * @var Utilities
     */
    public $utilities;

    /**
     * @var Formatter
     */
    public $formatter;

    /**
     * @var TypeRegistry
     */
    private $typeRegistry;

    /**
     * @var array
     */
    private $adminErrorMessages = array();

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->utilities = new Utilities();
        $this->options = new Options();

        $this->formatter = new Formatter($this->options); // TODO In Singleton umwandeln

        $this->data = new Data($this->options);
        new Frontend($this->options, $this->formatter);
        new Shortcodes($this, $this->formatter);
        new ReportNumberController();

        $this->typeRegistry = new TypeRegistry();

        if (is_admin()) {
            new Admin\Initializer($this->data, $this->options, $this->utilities);
        }

        $this->addHooks();
    }

    private function addHooks()
    {
        add_action('admin_notices', array($this, 'onAdminNotices'));
        add_action('init', array($this, 'onInit'));
        add_action('plugins_loaded', array($this, 'onPluginsLoaded'));
        register_activation_hook(self::$pluginFile, array($this, 'onActivation'));
        register_deactivation_hook(self::$pluginFile, array($this, 'onDeactivation'));
        add_action('widgets_init', array($this, 'registerWidgets'));

        $userRightsManager = new UserRightsManager();
        add_filter('user_has_cap', array($userRightsManager, 'userHasCap'), 10, 4);

        add_action('parse_query', array($this, 'einsatznummerMetaQuery'));
    }

    /**
     * Wird beim Aktivieren des Plugins aufgerufen
     */
    public function onActivation()
    {
        add_option('einsatzvw_db_version', self::DB_VERSION);

        $this->maybeUpdate();
        update_option('einsatzvw_version', self::VERSION);

        // Posttypen registrieren
        try {
            $this->typeRegistry->registerTypes();
        } catch (Exceptions\TypeRegistrationException $e) {
            array_push($this->adminErrorMessages, $e->getMessage());
            return;
        }
        $this->addRewriteRules();

        // Permalinks aktualisieren
        flush_rewrite_rules();
    }

    /**
     * Wird beim Deaktivieren des Plugins aufgerufen
     */
    public function onDeactivation()
    {
        // Permalinks aktualisieren (derzeit ohne Effekt, siehe https://core.trac.wordpress.org/ticket/29118)
        flush_rewrite_rules();
    }

    /**
     * Plugin initialisieren
     */
    public function onInit()
    {
        try {
            $this->typeRegistry->registerTypes();
        } catch (Exceptions\TypeRegistrationException $e) {
            array_push($this->adminErrorMessages, $e->getMessage());
            return;
        }
        $this->addRewriteRules();
        if ($this->options->isFlushRewriteRules()) {
            flush_rewrite_rules();
            $this->options->setFlushRewriteRules(false);
        }
    }

    public function onPluginsLoaded()
    {
        $this->maybeUpdate();
        update_option('einsatzvw_version', self::VERSION);
    }

    public function onAdminNotices()
    {
        if (empty($this->adminErrorMessages)) {
            return;
        }
        
        $pluginData = get_plugin_data(einsatzverwaltung_plugin_file());
        foreach ($this->adminErrorMessages as $errorMessage) {
            $message = sprintf('Plugin %s: %s', $pluginData['Name'], $errorMessage);
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr('notice notice-error'), esc_html($message));
        }
    }

    private function addRewriteRules()
    {
        global $wp_rewrite;
        if ($wp_rewrite->using_permalinks()) {
            $base = ltrim($wp_rewrite->front, '/') . $this->options->getRewriteSlug();
            add_rewrite_rule(
                $base . '/(\d{4})/page/(\d{1,})/?$',
                'index.php?post_type=einsatz&year=$matches[1]&paged=$matches[2]',
                'top'
            );
            add_rewrite_rule($base . '/(\d{4})/?$', 'index.php?post_type=einsatz&year=$matches[1]', 'top');
        }

        add_rewrite_tag('%einsatznummer%', '([^&]+)');
    }

    /**
     * @param \WP_Query $query
     */
    public function einsatznummerMetaQuery($query)
    {
        $enr = $query->get('einsatznummer');
        if (!empty($enr)) {
            $query->set('post_type', 'einsatz');
            $query->set('meta_key', 'einsatz_incidentNumber');
            $query->set('meta_value', $enr);
        }
    }

    public function registerWidgets()
    {
        register_widget(new RecentIncidents($this->options, $this->formatter));
        register_widget(new RecentIncidentsFormatted($this->formatter));
    }

    /**
     * Gibt den Link zu einem bestimmten Jahresarchiv zurück, berücksichtigt dabei die Permalink-Einstellungen
     *
     * @param string $year
     *
     * @return string
     */
    public function getYearArchiveLink($year)
    {
        global $wp_rewrite;
        $link = get_post_type_archive_link('einsatz');
        $link = ($wp_rewrite->using_permalinks() ? trailingslashit($link) : $link . '&year=') . $year;
        return user_trailingslashit($link);
    }

    private function maybeUpdate()
    {
        $currentDbVersion = get_option('einsatzvw_db_version');
        if (!empty($currentDbVersion) && $currentDbVersion >= self::DB_VERSION) {
            return;
        }

        $update = $this->getUpdater();
        $updateResult = $update->doUpdate($currentDbVersion, self::DB_VERSION);
        if (is_wp_error($updateResult)) {
            error_log("Das Datenbank-Upgrade wurde mit folgendem Fehler beendet: {$updateResult->get_error_message()}");
        }
    }

    /**
     * @return Update
     */
    public function getUpdater()
    {
        return new Update($this->data);
    }

    /**
     * @return Data
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Falls die einzige Instanz noch nicht existiert, erstelle sie
     * Gebe die einzige Instanz dann zurück
     *
     * @return   Core
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new Core();
        }
        return self::$instance;
    }
}