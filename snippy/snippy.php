<?php
/**
* Plugin Name: Snippy
* Plugin URI: http://pqina.nl/snippy
* Description: Snippy, super flexible shortcodes
* Version: 1.0.0
* Author: PQINA
* Author URI: http://pqina.nl
* License: GPL2
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: snippy
*/
namespace snippy;

// Get dependencies
require('inc/db.php');
require('inc/list.php');
require('inc/utils.php');

// Get views
require('view/tiny.php');
require('view/bits.php');
require('view/shortcodes.php');

// Class
class Snippy {

    // Snippy version and Snippy Database version
    public static $version = '1.0.0';

    private static $_instance = null;

    public static function get_instance() {

        if ( self::$_instance == null ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    private function __construct() {

        \register_activation_hook( __FILE__, array( $this, 'install' ) );

        \add_action( 'plugins_loaded', array($this, 'update' ) );

        \add_action( 'admin_menu', array( $this, 'admin_menu') );

        \add_action( 'init', array( $this, 'init') );

        \add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts') );

        \add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_scripts') );

    }

    public function init()
    {

        \load_plugin_textdomain('snippy', false, dirname(\plugin_basename(__FILE__)));

        if (is_admin()) {
            Tiny::setup();
        }
        else {
            $this->shortcodes();
        }
    }

    public function install() {

        Data::setup_db();

    }

    public function update() {

        Data::update_db();

    }

    public function admin_menu() {

        \add_menu_page(
            \__('Snippy', 'snippy'),
            \__('Snippy', 'snippy'),
            'activate_plugins',
            'snippy',
            array( 'snippy\Shortcodes_View', 'handle_overview'),
            'dashicons-editor-code',
            60
        );

        \add_submenu_page(
            'snippy',
            \__('Shortcodes', 'snippy'),
            \__('Shortcodes', 'snippy'),
            'activate_plugins',
            'snippy',
            array( 'snippy\Shortcodes_View', 'handle_overview')
        );

        \add_submenu_page(
            'snippy',
            \__('Add Shortcode', 'snippy'),
            \__('Add Shortcode', 'snippy'),
            'activate_plugins',
            'snippy_edit_shortcode',
            array( 'snippy\Shortcodes_View', 'handle_edit')
        );

        \add_submenu_page(
            'snippy',
            \__('Bits', 'snippy'),
            \__('Bits', 'snippy'),
            'activate_plugins',
            'snippy_bits',
            array( 'snippy\Bits_View', 'handle_overview')
        );

        \add_submenu_page(
            'snippy',
            \__('Add Bit', 'snippy'),
            \__('Add Bit', 'snippy'),
            'activate_plugins',
            'snippy_edit_bit',
            array( 'snippy\Bits_View', 'handle_edit')
        );

    }

    public function register_admin_scripts() {

        wp_enqueue_style( 'snippy-admin-styles', plugin_dir_url( __FILE__ ) . 'style.css', array(), Snippy::$version );
        wp_enqueue_script( 'snippy-admin-scripts', plugin_dir_url( __FILE__ ) . 'script.js', array(), Snippy::$version, true );

    }

    public function register_scripts() {

        $upload_url = wp_upload_dir()['baseurl'];
        $bits = Data::get_entries_all('bits');
        foreach($bits as $bit) {
            if ($bit['type'] === 'script') {
                wp_register_script( $bit['name'], $upload_url . $bit['value'], array(), false, true );
            }
            else if ($bit['type'] === 'stylesheet') {
                wp_register_style( $bit['name'], $upload_url . $bit['value'] );
            }
        }

    }

    private function shortcodes() {

        $shortcode_entries = Data::get_entries_all('shortcodes');

        foreach ($shortcode_entries as $shortcode_entry) {
            add_shortcode($shortcode_entry['name'], array( $this , 'handle_shortcode'));
        }
    }

    public function handle_shortcode($atts, $content, $tag) {

        $hasContent = strlen($content) > 0;

        // set base output
        $output = '';

        // get bits for shortcode with this id
        $bits = Data::get_bits_for_shortcode_by_name($tag);

        // use bits
        foreach ($bits as $bit) {

            $bit_name = $bit['name'];
            $bit_type = $bit['type'];
            $bit_value = $bit['value'];

            // if is script, enqueue
            if ($bit_type === 'script') {
                wp_enqueue_script( $bit_name );
            }

            // if is stylesheet, enqueue
            else if ($bit_type === 'stylesheet') {
                wp_enqueue_style( $bit_name );
            }

            // if is CSS wrap in <style> tags and prepend to output
            else if ($bit_type === 'css') {

                // replace placeholders in css value
                $css = html_entity_decode($bit_value);
                $placeholders_merged = Utils::merge_placeholders_and_atts($bit, $atts);
                $css = Utils::replace_placeholders($placeholders_merged, $css);

                $output .= "<style>$css</style>";
            }

            // if is HTML, add to output and replace placeholders with $atts
            else if ($bit_type === 'html') {

                // replace placeholders in html value
                $html = html_entity_decode($bit_value);
                $placeholders_merged = Utils::merge_placeholders_and_atts($bit, $atts);

                // if has content add content to placeholder
                if ($hasContent) {
                    $placeholders_merged = array_filter($placeholders_merged, function($placeholder) {
                        return $placeholder['name'] !== 'content';
                    });
                }
                // has content attr
                elseif (isset($atts['content'])) {
                    foreach ($placeholders_merged as &$placeholder) {
                        if ($placeholder['name'] === 'content') {
                            $placeholder['value'] = $atts['content'];
                        }
                    }
                }

                $html = Utils::replace_placeholders($placeholders_merged, $html);

                // if has {{content}}, replace with do_shortcodes($content);
                if ($hasContent) {
                    $html = str_replace('{{content}}', \do_shortcode($content), $html);
                }

                // done
                $output .= $html;
            }

            // if is JS wrap in <script> tags and append to output
            else if ($bit_type === 'js') {

                // replace placeholders in js value
                $js = html_entity_decode($bit_value);
                $placeholders_merged = Utils::merge_placeholders_and_atts($bit, $atts);
                $js = Utils::replace_placeholders($placeholders_merged, $js);

                $output .= "<script>$js</script>";
            }


        }

        // render output
        return $output;

    }

}

// go!
Snippy::get_instance();