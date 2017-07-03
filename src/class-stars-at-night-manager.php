<?php

/*
 * MIT License
 *
 * Copyright (c) 2016 Sean Leary
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
defined ( 'ABSPATH' ) or die ();

include ('class-sunrise-sunset.php');
include ('class-moonrise-moonset.php');
include ('class-moon-phase.php');
include ('class-satellite-passes.php');
include ('class-planet-passes.php');
include ('class-table-build-helper.php');

/**
 * This class calculates and emits astronomical values in an HTML table.
 * For testing, the values are just written to stdout
 */
class Stars_At_Night_Manager {
    // WordPress required properties
    protected $loader;
    protected $plugin_name;
    protected $version;
    
    // sanitized user input
    private $sanitized_name;
    private $sanitized_lat;
    private $sanitized_long;
    private $sanitized_timezone;
    private $sanitized_days;
    private $sanitized_graphical;
    private $sanitized_refresh;
    private $sanitized_suppressDegrees;
    
    // calculated values
    private $startDate;
    private $endDate;
    private $satellitePasses;
    private $planetPasses;
    private $sunriseSunset;
    private $tableBuildHelper;
    
    public function get_sanitized_name() { return $this->sanitized_name; }
    public function get_sanitized_lat() { return $this->sanitized_lat; }
    public function get_sanitized_long() { return $this->sanitized_long; }
    public function get_sanitized_timezone() { return $this->sanitized_timezone; }
    public function get_sanitized_days() { return $this->sanitized_days; }
    public function get_sanitized_graphical() { return $this->sanitized_graphical; }
    public function get_sanitized_refresh() { return $this->sanitized_refresh; }
    public function get_sanitized_suppressDegrees() { return $this->sanitized_suppressDegrees; }
    public function getStartDate() { return $this->startDate; }
    public function getEndDate() { return $this->endDate; }
    public function getSatellitePasses() { return $this->satellitePasses; }
    public function getPlanetPasses() { return $this->planetPasses; }
    public function getSunriseSunset() { return $this->sunriseSunset; }
    
    /**
     * create and initialize a class instance
     */
    public function __construct() {
        if (defined ( 'WPINC' )) {
            $this->plugin_name = 'stars-at-night';
            $this->version = '1.0';
            
            $this->define_admin_hooks ();
            $this->define_public_hooks ();
        }
    }
    
    /**
     * This class does perform WordPress Admin functionality
     */
    private function define_admin_hooks() {
        // Any admin hooks...
    }
    
    /**
     * These are how the plugin interacts with WordPress
     */
    private function define_public_hooks() {
        add_action ( 'init', array ($this,'register_shortcodes' 
        ) );
        add_action ( 'init', array ($this,'enqueuestyles' 
        ) );
    }
    
    /**
     * This is how the plugin is known to WordPress
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }
    
    /**
     * Report plugin version to WordPress
     */
    public function get_version() {
        return $this->version;
    }
    
    /**
     * WordPress shortcodes for this plugin
     */
    public function register_shortcodes() {
        add_shortcode ( 'stars-at-night', array ($this,'run_stars_at_night' 
        ) );
    }
    
    /**
     * CSS for the plugin
     */
    public function enqueuestyles() {
        wp_enqueue_style ( 'ngc2244_stars_at_night_css', 
                plugins_url ( '../css/stars-at-night.css', __FILE__ ), array (), $this->version );
    }
    
    /**
     * Here is where all of the work is done.
     *
     * @param $atts array
     *            An array of parameter values with '=' delimiters inside each array element,
     *            except for the first param which is the program name, and is ignored.
     *            Remaining params (order is unimportant):
     *            name: the name of the location to be calculated
     *            lat: latitude of location in fractional degrees (e.g. 30.8910).
     *            Positive is north, negative is south of equator
     *            long: longitude of location in fractional degrees (e.g.-98.4265).
     *            Positive is east, negative is west of the UTC line
     *            timezone: timezone name, must be value recognized by php.
     *            See http://php.net/manual/en/timezones.php
     *            days: number of days to report, starting from today
     *            
     *            graphical=not used at present. Will cause an image of the Moon phase to be
     *            displayed.
     */
    public function run_stars_at_night($atts) {
        if (! defined ( 'WPINC' )) {
            die ();
        }
        
        $this->satellitePasses = new NGC2244_Satellite_Passes ();
        $this->planetPasses = new NGC2244_Planet_Passes ();
        $this->sunriseSunset = new NGC2244_Sunrise_Sunset ();
        $this->tableBuildHelper = new NGC2244_Table_Build_Helper($this);
        
        /**
         * these are the supported fields of raw user input
         */
        $name = '';
        $lat = '';
        $long = '';
        $timezone = '';
        $days = '';
        $graphical = '';
        $refresh = false;
        $suppressDegrees = false;
        
        extract ( 
                shortcode_atts ( 
                        array ('name' => '','lat' => '','long' => '','timezone' => '','days' => '3',
                                'graphical' => '','refresh' => false,'suppressDegrees' => false 
                        ), $atts, 'stars-at-night' ), EXTR_IF_EXISTS );
        
        /**
         * Make sure the incoming data is valid.
         * If not, errors will be reported in the return string
         * and the method stops here
         */
        $validator_result = $this->data_validator ( $name, $lat, $long, $timezone, $days, 
                $graphical, $refresh, $suppressDegrees );
        if (! empty ( $validator_result )) {
            return $validator_result;
        }
        $today = new DateTime ( 'now', new DateTimeZone ( $timezone ) );
        $this->startDate = new DateTime ( $today->format ( 'm/d/Y' ) );
        // error_log ( 'startdate ' . $this->startDate->format ( 'm/d/Y' ) );
        $this->endDate = new DateTime ( $today->format ( 'm/d/Y' ) );
        $this->endDate->add ( new DateInterval ( 'P' . ($this->sanitized_days - 1) . 'D' ) );
        // error_log ( 'enddate ' . $this->endDate->format ( 'm/d/Y' ) );
        // get the tables
        $sunAndMoonTable = $this->getSunAndMoonTable ();
        $issTable = $this->getISSTable ( $refresh, $suppressDegrees );
        $iridiumTable = $this->getIridiumTable ( $refresh, $suppressDegrees );
        $planetTable = $this->getPlanetTable ( $refresh );
        $mobileText = '<style>
        .is-mobile {
            display: none;
        }
        @media (max-width: 600px) {
            .is-default {
                display: none;
            }
            .is-mobile {
                display: block;
            }
        }</style>';
        return $sunAndMoonTable . "<p>" . $planetTable . "<p>" . $issTable . "<p>" . $iridiumTable . $mobileText;

    }
    
    /**
     * Planettable is just for today
     *
     * @param $refresh boolean
     *            if true, get from server instead of cache
     * @return table of planets for today
     */
    private function getPlanetTable($refresh) {
        $planetTable = $this->planetPasses->get_planet_table ( $this->sanitized_lat, 
                $this->sanitized_long, $this->sanitized_timezone, $this->sunriseSunset, $refresh );
        return $planetTable;
    }
    
    /**
     * Iridium table can only look ahead 7 days, so calculate end date to at most 7 days, but pass
     * in the actual days in case we need to call this out in the table header.
     *
     * @param $refresh boolean
     *            if true, get from server instead of cache
     * @param $suppressDegrees boolean
     *            if true, omit degree symbol from table
     * @return table of iridium flares for the request time period, starting today
     */
    private function getIridiumTable($refresh, $suppressDegrees) {
        $iridiumDays = (($this->sanitized_days > 7) ? 7 : $this->sanitized_days);
        $iridiumEndDate = new DateTime ( $this->startDate->format ( 'm/d/Y' ) );
        $iridiumEndDate->add ( new DateInterval ( 'P' . ($iridiumDays - 1) . 'D' ) );
        // error_log ( 'enddate ' . $this->endDate->format ( 'm/d/Y' ) );
        $iridiumTable = $this->satellitePasses->get_iridium_table ( $this->sanitized_lat, 
                $this->sanitized_long, $this->sanitized_timezone, $this->startDate, $iridiumEndDate, 
                $this->sanitized_days, $this->sanitized_refresh, $this->sanitized_suppressDegrees );
        return $iridiumTable;
    }
    
    /**
     * ISS table can look ahead 10 days, same as the max days user can request, so no modification
     * of the end date is needed
     *
     * @param $refresh boolean
     *            if true, get from server instead of cache
     * @param $suppressDegrees boolean
     *            if true, omit degree symbol from table
     * @return table of ISS passes for the request timer period, starting today
     */
    private function getISSTable($refresh, $suppressDegrees) {
        $issTable = $this->satellitePasses->get_iss_table ( $this->sanitized_lat, 
                $this->sanitized_long, $this->sanitized_timezone, $this->startDate, $this->endDate, 
                $refresh, $suppressDegrees );
        return $issTable;
    }

    /**
     * Returns a string containing the HTML to render a table of
     * night sky data inside a div.
     * A leading table description is included as well.
     *
     * @return html table of event times
     */
    private function getSunAndMoonTable() {
        return $this->tableBuildHelper->getSunAndMoonTableMobile() . 
            $this->tableBuildHelper->getSunAndMoonTableFull();
    }

    /**
     * Validates the parameters sent by the user.
     *
     * @param $name string
     *            name of the location to be calculated
     * @param $lat float
     *            latitude of location in fractional degrees
     * @param $long float
     *            longitude of location in fractional degrees
     * @param $timezone string
     *            timezone name, must be value recognized by php
     * @param $days int
     *            number of days to report
     * @param $graphical bool
     *            not used at present
     * @param $refresh bool
     *            if true, force a transient cache refresh for all tables
     * @param $suppressDegrees bool
     *            if true, suppress the degree symbol for all tables
     * @return string containing error messages, or empty if no errors found
     */
    private function data_validator($name, $lat, $long, $timezone, $days, $graphical, $refresh, 
            $suppressDegrees) {
        $result = "";
        /**
         * Name must be safe, but can be any value, up to 32 chars
         */
        if (strlen ( $name ) > 32) {
            $name = substr ( $name, 32 );
        }
        $filterFlags = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_ENCODE_AMP;
        $this->sanitized_name = filter_var ( $name, FILTER_SANITIZE_STRING, $filterFlags );
        
        /**
         * lat must be valid fractional decimal [-90:90]
         */
        if (! is_numeric ( $lat )) {
            $result .= " Latitude must be numeric.";
        } else if ($lat < (- 90) || $lat > 90) {
            $result .= " Latitude must be in the range -90 to 90.";
        } else {
            $this->sanitized_lat = $lat;
        }
        
        /**
         * long must be valid fractional decimal [-180:180]
         */
        if (! is_numeric ( $long )) {
            $result .= " Longitude must be numeric.";
        } else if ($long < (- 180) || $long > 180) {
            $result .= " Longitude must be in the range -180 to 180.";
        } else {
            $this->sanitized_long = $long;
        }
        
        /**
         * timezone must be recognized by php
         */
        if (! in_array ( $timezone, DateTimeZone::listIdentifiers () )) {
            $result .= " Timezone contains an unrecognized value.";
        } else {
            $this->sanitized_timezone = $timezone;
        }
        
        /**
         * days must be valid int [1:10].
         * Total of date+days must not exceed 10.
         */
        if (! is_numeric ( $days )) {
            $result .= " Days must be numeric.";
        } else if ($days < 1 || $days > 10) {
            $result .= " Days must be in the range 1 to 10.";
        } else {
            $this->sanitized_days = $days;
        }
        
        // for now, graphical is ignored
        
        if (! empty ( $result )) {
            $result = "Errors: " . $result;
        }
        // validate the booleans
        if (! (strcmp ( 'true', $refresh ))) {
            $this->sanitized_refresh = true;
        } else {
            $this->sanitized_refresh = false;
        }
        if (! (strcmp ( 'true', $suppressDegrees ))) {
            $this->sanitized_suppressDegrees = true;
        } else {
            $this->sanitized_suppressDegrees = false;
        }
        return $result;
    }
} 
