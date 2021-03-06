<?php
/**
 * OpenCast.class.php - A course plugin for Stud.IP which includes an opencast player
 */
require_once __DIR__ . '/bootstrap.php';

use Opencast\LTI\OpencastLTI;
use Opencast\LTI\LtiLink;
use Opencast\Models\OCConfig;
use Opencast\Models\OCSeminarSeries;


class OpenCast extends StudipPlugin implements SystemPlugin, StandardPlugin
{
    const GETTEXT_DOMAIN = 'opencast';

    /**
     * Initialize a new instance of the plugin.
     */
    public function __construct()
    {
        parent::__construct();

        bindtextdomain(static::GETTEXT_DOMAIN, $this->getPluginPath() . '/locale');
        bind_textdomain_codeset(static::GETTEXT_DOMAIN, 'UTF-8');

        $GLOBALS['ocplugin_path'] = $this->getPluginURL();

        if ($GLOBALS['perm']->have_perm('root')) {
            //check if we already have an connection to an opencast matterhorn
            //.. now the subnavi
            $main = new Navigation($this->_("Opencast Administration"));
            // TODO think about an index page.. for the moment the config page is in charge..
            $main->setURL(PluginEngine::getURL($this, [], 'admin/config'));

            $config = new Navigation($this->_('Opencast Einstellungen'));
            $config->setURL(PluginEngine::getURL($this, [], 'admin/config'));
            $main->addSubNavigation('oc-config', $config);

            Navigation::addItem('/start/opencast', $main);
            Navigation::addItem('/admin/config/oc-config', $config);

            if (OCModel::getConfigurationstate()) {
                $resources = new Navigation($this->_('Opencast Ressourcen'));
                $resources->setURL(PluginEngine::getURL($this, [], 'admin/resources'));
                $main->addSubNavigation('oc-resources', $resources);
                Navigation::addItem('/admin/config/oc-resources', $resources);
            }
        }

        if (!$GLOBALS['opencast_already_loaded']) {
            $this->addStylesheet('stylesheets/oc.less');
            PageLayout::addScript($this->getPluginUrl() . '/dist/application.js');
            if ($GLOBALS['perm']->have_perm('tutor') && OCModel::getConfigurationstate()) {
                PageLayout::addScript($this->getPluginUrl() . '/dist/embed.js');
                PageLayout::addStylesheet($this->getpluginUrl() . '/stylesheets/embed.css');
            }
            if (OCModel::getConfigurationstate()) {
                StudipFormat::addStudipMarkup('opencast', '\[opencast\]', '\[\/opencast\]', 'OpenCast::markupOpencast');
            }
            NotificationCenter::addObserver($this, 'NotifyUserOnNewEpisode', 'NewEpisodeForCourse');
        }

        $GLOBALS['opencast_already_loaded'] = true;
    }

    /**
     * Plugin localization for a single string.
     * This method supports sprintf()-like execution if you pass additional
     * parameters.
     *
     * @param String $string String to translate
     * @return translated string
     */
    public function _($string)
    {
        $result = static::GETTEXT_DOMAIN === null
            ? $string
            : dcgettext(static::GETTEXT_DOMAIN, $string, LC_MESSAGES);
        if ($result === $string) {
            $result = _($string);
        }

        if (func_num_args() > 1) {
            $arguments = array_slice(func_get_args(), 1);
            $result    = vsprintf($result, $arguments);
        }

        return $result;
    }

    /**
     * Plugin localization for plural strings.
     * This method supports sprintf()-like execution if you pass additional
     * parameters.
     *
     * @param String $string0 String to translate (singular)
     * @param String $string1 String to translate (plural)
     * @param mixed $n Quantity factor (may be an array or array-like)
     * @return translated string
     */
    public function _n($string0, $string1, $n)
    {
        if (is_array($n)) {
            $n = count($n);
        }

        $result = static::GETTEXT_DOMAIN === null
            ? $string0
            : dngettext(static::GETTEXT_DOMAIN, $string0, $string1, $n);
        if ($result === $string0 || $result === $string1) {
            $result = ngettext($string0, $string1, $n);
        }

        if (func_num_args() > 3) {
            $arguments = array_slice(func_get_args(), 3);
            $result    = vsprintf($result, $arguments);
        }

        return $result;
    }

    /**
     * This method takes care of the Navigation
     *
     * @param string   course_id
     * @param string   last_visit
     */
    public function getIconNavigation($course_id, $last_visit, $user_id = null)
    {
        $ocmodel = new OCCourseModel($course_id);
        if (!$this->isActivated($course_id)) {
            return;
        }

        $this->image_path = $this->getPluginURL() . '/images/';
        if ($GLOBALS['perm']->have_studip_perm('user', $course_id)) {
            $ocgetcount = $ocmodel->getCount($last_visit);
            $text       = sprintf(
                $this->_('Es gibt %s neue Opencast Aufzeichnung(en) seit ihrem letzten Besuch.'),
                $ocgetcount
            );
        } else {
            $num_entries = 0;
            $text        = $this->_('Opencast Aufzeichnungen');
        }

        $navigation = new Navigation(
            'opencast',
            PluginEngine::getURL($this, [], 'course/index/false')
        );
        $navigation->setBadgeNumber($num_entries);
        $navigation->setDescription($text);
        if ($ocgetcount > 0) {
            $navigation->setImage(
                Icon::create($this->getPluginURL() . '/images/opencast-red.svg',
                    Icon::ROLE_ATTENTION,
                    ['title' => 'Opencast']
                ));
        } else {
            $navigation->setImage(
                Icon::create($this->getPluginURL() . '/images/opencast-grey.svg',
                    Icon::ROLE_INACTIVE,
                    ['title' => 'Opencast']
                ));
        }

        return $navigation;
    }

    /**
     * Return a template (an instance of the Flexi_Template class)
     * to be rendered on the course summary page. Return NULL to
     * render nothing for this plugin.
     *
     * The template will automatically get a standard layout, which
     * can be configured via attributes set on the template:
     *
     *  title        title to display, defaults to plugin name
     *  icon_url     icon for this plugin (if any)
     *  admin_url    admin link for this plugin (if any)
     *  admin_title  title for admin link (default: Administration)
     *
     * @return object   template object to render or NULL
     */
    public function getInfoTemplate($course_id)
    {
        return null;
    }

    public function getTabNavigation($course_id)
    {
        if (!$this->isActivated($course_id) || !OCModel::getConfigurationstate()) {
            return;
        }

        $ocmodel = new OCCourseModel($course_id);
        $main    = new Navigation('Opencast');
        $main->setURL(PluginEngine::getURL($this, [], 'course/index'));
        $main->setImage(Icon::create(
            $this->getPluginURL() . '/images/opencast-black.svg',
            Icon::ROLE_CLICKABLE,
            ['title' => 'Opencast']
        ));
        $main->setImage(Icon::create(
            $this->getPluginURL() . '/images/opencast-red.svg',
            Icon::ROLE_ATTENTION,
            ['title' => 'Opencast']
        ));

        $overview = new Navigation($this->_('Aufzeichnungen'));
        $overview->setURL(PluginEngine::getURL($this, [], 'course/index'));
        $main->addSubNavigation('overview', $overview);

        $course = Seminar::getInstance($course_id);

        if ($GLOBALS['perm']->have_studip_perm('tutor', $course_id)
            && !$course->isStudygroup()) {
            $scheduler = new Navigation($this->_('Aufzeichnungen planen'));
            $scheduler->setURL(PluginEngine::getURL($this, [], 'course/scheduler'));

            $series_metadata = OCSeminarSeries::getSeries($course_id);
            if ($series_metadata && $series_metadata[0]['schedule'] == '1') {
                $main->addSubNavigation('scheduler', $scheduler);
            }
        }

        $studyGroupId = CourseConfig::get($course_id)->OPENCAST_MEDIAUPLOAD_STUDY_GROUP;

        if (!empty($studyGroupId)) {
            $studyGroup = new Navigation($this->_('Zur Studiengruppe'));
            $studyGroup->setURL(PluginEngine::getURL($this, ['cid' => $$linkedCourseId], 'course/redirect_studygroup/' . $studyGroupId));
            $main->addSubNavigation('studygroup', $studyGroup);
        }

        $linkedCourseId = CourseConfig::get($course_id)->OPENCAST_MEDIAUPLOAD_LINKED_COURSE;
        if (!empty($linkedCourseId)) {
            $linkedCourse = new Navigation($this->_('Zur verknüpften Veranstaltung'));
            $linkedCourse->setURL(PluginEngine::getURL($this, ['cid' => $linkedCourseId], 'course/index'));
            $main->addSubNavigation('linkedcourse', $linkedCourse);
        }

        if ($ocmodel->getSeriesVisibility() == 'visible' || $GLOBALS['perm']->have_studip_perm('tutor', $course_id)) {
            return ['opencast' => $main];
        }
        return [];
    }

    /**
     * return a list of ContentElement-objects, containing
     * everything new in this module
     *
     * @param string $course_id the course-id to get the new stuff for
     * @param int $last_visit when was the last time the user visited this module
     * @param string $user_id the user to get the notification-objects for
     *
     * @return array an array of ContentElement-objects
     */
    public function getNotificationObjects($course_id, $since, $user_id)
    {
        return false;
    }

    public static function markupOpencast($markup, $matches, $contents)
    {
        $series_id       = OCModel::getSeriesForEpisode($contents);
        $course_id       = OCConfig::getCourseIdForSeries($series_id);
        $connectedSeries = OCSeminarSeries::getSeries($course_id);
        $config          = OCConfig::getConfigForCourse($course_id);

        $search_client = SearchClient::getInstance($config['config_id']);

        // TODO: get player type from config
        $embed = $search_client->getBaseURL() . "/paella/ui/embed.html?id=" . $contents;
        #$embed = $search_client->getBaseURL() . "/engage/theodul/ui/core.html?mode=embed&id=" . $contents;

        $current_user_id = $GLOBALS['auth']->auth['uid'];
        $lti_link        = new LtiLink(
            OpencastLTI::getSearchUrl($course_id),
            $config['lti_consumerkey'],
            $config['lti_consumersecret']
        );

        if ($GLOBALS['perm']->have_studip_perm('tutor', $course_id, $current_user_id)) {
            $role = 'Instructor';
        } else if ($GLOBALS['perm']->have_studip_perm('autor', $course_id, $current_user_id)) {
            $role = 'Learner';
        }

        $lti_link->setUser($current_user_id, $role);
        $lti_link->setCourse($course_id);
        $lti_link->setResource(
            $connectedSeries,
            'series',
            'view complete series for course'
        );

        $launch_data = $lti_link->getBasicLaunchData();
        $signature   = $lti_link->getLaunchSignature($launch_data);

        $launch_data['oauth_signature'] = $signature;

        $lti_data = json_encode($launch_data);
        $lti_url  = $lti_link->getLaunchURL();

        $id = md5(uniqid());

        return "<script>
        OC.ltiCall('$lti_url', $lti_data, function() {
            jQuery('#$id').attr('src', '$embed');
        });
        </script>"
            . sprintf('<iframe id="%s"
                style="border:0px #FFFFFF none;"
                name="Opencast - Media Player"
                scrolling="no"
                frameborder="0"
                marginheight="0px"
                marginwidth="0px"
                width="640" height="360"
                allow="fullscreen" webkitallowfullscreen="true" mozallowfullscreen="true"
            ></iframe><br>', $id);
    }

    public function NotifyUserOnNewEpisode($x, $data)
    {
        $ocmodel = new OCCourseModel($data['course_id']);
        if ($ocmodel->getSeriesVisibility() == 'visible') {
            $course  = Course::find($data['course_id']);
            $members = $course->members;

            $users = [];
            foreach ($members as $member) {
                $users[] = $member->user_id;
            }

            $notification = sprintf($this->_('Neue Vorlesungsaufzeichnung  "%s" im Kurs "%s"'), $data['episode_title'], $course->name);
            PersonalNotifications::add(
                $users, PluginEngine::getLink($this, [], 'course/index/' . $data['episode_id']),
                $notification, $data['episode_id'],
                Assets::image_path('icons/black/file-video.svg')
            );
        }

    }

    /**
     * @inherits
     *
     * Overwrite default metadata-function to return correctly encoded strings
     * depending on Stud.IP version
     *
     * @return array correctly encoded metadata
     */
    public function getMetadata()
    {
        $metadata = parent::getMetadata();

        $metadata['pluginname'] = $this->_("Opencast");
        $metadata['displayname'] = $this->_("Opencast");

        $metadata['description'] = $this->_("Mit diesem Tool können Videos aus dem Vorlesungsaufzeichnungssystem "
            . "(Opencast) mit einer Stud.IP-Veranstaltung verknüpft werden. Die Aufzeichnungen werden in "
            . "einem eingebetteten Player in Stud.IP zur Verfügung gestellt. Darüberhinaus ist es mit "
            . "dieser Integration möglich die komplette Aufzeichnungsplanung für eine Veranstaltung "
            . "abzubilden. Voraussetzung hierfür sind entsprechende Einträge im Ablaufplan und eine "
            . "gebuchte Ressource mit einem Opencast-Capture-Agent. Vorhandene Medien können bei "
            . "Bedarf nachträglich über die Hochladen-Funktion zur verknüpften Serie hinzugefügt werden."
        );

        $metadata['summary'] = $this->_("Vorlesungsaufzeichnung");

        return $metadata;
    }

    public static function get_plugin_id()
    {
        $statement = DBManager::get()->prepare('SELECT pluginid
            FROM plugins WHERE pluginclassname = ?');
        $statement->execute(['OpenCast']);
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($result && count($result[0]) > 0) {
            return $result[0]['pluginid'];
        }
        return -1;
    }

    public static function activated_in_courses()
    {
        $statement = DBManager::get()->prepare("SELECT range_id FROM plugins_activated
            WHERE range_type = 'sem'
                AND pluginid = ?");
        $statement->execute([OpenCast::get_plugin_id()]);
        $result    = $statement->fetchAll(PDO::FETCH_ASSOC);
        $to_return = [];
        if ($result) {
            foreach ($result as $entry) {
                $to_return[] = $entry['range_id'];
            }
        }
        return $to_return;
    }

    /**
     * Return the name of this plugin.
     */
    public function getPluginName()
    {
        return 'Opencast';
    }
    function getMetadata() {
        $metadata = parent::getMetadata();
        $metadata['pluginname'] = _("OpenCast");
        $metadata['displayname'] = _("OpenCast");
        $metadata['descriptionlong'] = _("Mit diesem Tool können Videos aus dem Vorlesungsaufzeichnungssystem (Opencast) mit einer Stud.IP-Veranstaltung verknüpft werden. Die Aufzeichnungen werden in einem eingebetteten Player in Stud.IP zur Verfügung gestellt. Darüberhinaus ist es mit dieser Integration möglich die komplette Aufzeichnungsplanung für eine Veranstaltung abzubilden. Voraussetzung hierfür sind entsprechende Einträge im Ablaufplan und eine gebuchte Ressource mit einem Opencast-Capture-Agent. Vorhandene Medien können bei Bedarf nachträglich über die Upload-Funktion zur verknüpften Serie hinzugefügt werden.");
        $metadata['summary'] = _("Vorlesungsaufzeichnung");
        return $metadata;
    }
}
