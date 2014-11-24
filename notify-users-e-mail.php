<?php
/*
 * Post Notification by Email.
 *
 * @package   Post Notification by Email
 * @author    Valerio Souza <eu@valeriosouza.com.br>
 * @license   GPL-2.0+
 * @link      http://wordpress.org/plugins/notify-users-e-mail/
 * @copyright 2013 CodeHost
 *
@wordpress-plugin
Plugin Name:       Post Notification by Email
Plugin URI:        http://wordpress.org/plugins/notify-users-e-mail/
Description:       Notification of new posts by e-mail to all users
Version:           4.0.1
Author:            Valerio Souza, Claudio Sanches
Author URI:        http://valeriosouza.com.br
Text Domain:       notify-users-e-mail
License:           GPL-2.0+
License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
Domain Path:       /languages
GitHub Plugin URI: https://github.com/valeriosouza/post-notification-by-email
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

define( '__NTF_USR_FILE__', __FILE__ );

if ( ! class_exists( 'Notify_Users_EMail' ) ) :

/**
 * Notify Users E-Mail class.
 *
 * @package Post Notification by Email
 * @author  Valerio Souza <eu@valeriosouza.com.br>
 */
class Notify_Users_EMail {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '4.0.1';

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin.
	 */
	private function __construct() {

		// Load plugin text domain.
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Activate plugin when new blog is added.
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );

		/**
		 * Admin actions.
		 */
		if ( is_admin() && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			$this->admin_include();
			add_action( 'admin_init', array( $this, 'update' ) );
		}

		// Nofity users when publish a post.
		add_action( 'wp_insert_post', array( $this, 'send_notification_post' ), 10, 2 );

		// Nofity users when publish a comment.
		add_action( 'wp_insert_comment', array( $this, 'send_notification_comment' ), 10, 2 );
	}

	/**
	 * Admin includes
	 *
	 * @return void
	 */
	private function admin_include() {
		require_once 'includes/class-notify-users-e-mail-admin.php';
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param  boolean $network_wide True if WPMU superadmin uses
	 *                               "Network Activate" action, false if
	 *                               WPMU is disabled or plugin is
	 *                               activated on an individual blog.
	 *
	 * @return void
	 */
	public static function activate( $network_wide ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_wide  ) {

				// Get all blog ids
				$blog_ids = self::get_blog_ids();

				foreach ( $blog_ids as $blog_id ) {
					switch_to_blog( $blog_id );
					self::single_activate();
				}

				restore_current_blog();
			} else {
				self::single_activate();
			}
		} else {
			self::single_activate();
		}
	}

	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param    int    $blog_id    ID of the new blog.
	 */
	public function activate_new_site( $blog_id ) {
		if ( 1 !== did_action( 'wpmu_new_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		self::single_activate();
		restore_current_blog();
	}

	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted
	 *
	 * @return   array|false    The blog ids, false if no matches.
	 */
	private static function get_blog_ids() {
		global $wpdb;

		// Get an array of blog ids.
		$sql = "SELECT blog_id FROM $wpdb->blogs
			WHERE archived = '0' AND spam = '0'
			AND deleted = '0'";

		return $wpdb->get_col( $sql );
	}

	/**
	 * Fired for each blog when the plugin is activated.
	 *
	 * @return   void
	 */
	private static function single_activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
		check_admin_referer( 'activate-plugin_' . $plugin );

		$args = array(
			'type'                     => 'post',
			'child_of'                 => 0,
			'parent'                   => '',
			'orderby'                  => 'id',
			'order'                    => 'ASC',
			'hide_empty'               => 1,
			'hierarchical'             => 1,
			'exclude'                  => '',
			'include'                  => '',
			'number'                   => '',
			'taxonomy'                 => 'category',
			'pad_counts'               => false 

		); 
		$list_categories = get_categories( $args );
		$array_category = array();
		foreach ($list_categories as $item_category) {
			$array_category[] = $item_category->cat_ID; 
		};

		$options = array(
			'send_to'          				=> '',
			'send_to_users'    				=> array_keys( get_editable_roles() ),
			'subject_post'     				=> sprintf( __( 'New post published at %s on {date}', 'notify-users-e-mail' ), get_bloginfo( 'name' ) ),
			'body_post'        				=> __( 'A new post {title} - {link_post} has been published on {date}.', 'notify-users-e-mail' ),
			'subject_comment'  				=> sprintf( __( 'New comment published at %s', 'notify-users-e-mail' ), get_bloginfo( 'name' ) ),
			'body_comment'     				=> __( 'A new comment {link_comment} has been published.', 'notify-users-e-mail' ),
			'conditional_post_type'			=> array( 'post', 'page' ),
			'conditional_taxonomy_post_tag' => '',
			'conditional_taxonomy_category' => $array_category,
		);

		add_option( 'notify_users_email', $options );
		add_option( 'notify_users_email_version', self::VERSION );
	}

	/**
	 * Update the plugin options.
	 *
	 * Make update to version 2.0.0.
	 *
	 * @return   void
	 */
	public function update() {
		$version = get_option( 'notify_users_email_version' );

		if ( empty( $version ) ) {
			if ( get_option( 'notify_users_mail' ) ) {
				$options = array(
					'send_to'         => get_option( 'notify_users_mail' ),
					'send_to_users'   => array_keys( get_editable_roles() ),
					'subject_post'    => get_option( 'notify_users_subject_post' ),
					'body_post'       => get_option( 'notify_users_body_post' ),
					'subject_comment' => get_option( 'notify_users_subject_comment' ),
					'body_comment'    => get_option( 'notify_users_body_comment' ),
				);

				// Remove old options.
				delete_option( 'notify_users_mail' );
				delete_option( 'notify_users_subject_post' );
				delete_option( 'notify_users_body_post' );
				delete_option( 'notify_users_subject_comment' );
				delete_option( 'notify_users_body_comment' );

				// Save new options.
				update_option( 'notify_users_email', $options );
				update_option( 'notify_users_email_version', self::VERSION );
			}
		} elseif ($version <> self::VERSION) {
			update_option( 'notify_users_email_version', self::VERSION );
		}
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return   void
	 */
	public function load_plugin_textdomain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'notify-users-e-mail' );

		load_textdomain( 'notify-users-e-mail', trailingslashit( WP_LANG_DIR ) . 'notify-users-e-mail/notify-users-e-mail-' . $locale . '.mo' );
		load_plugin_textdomain( 'notify-users-e-mail', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Get formated date.
	 *
	 * @return string
	 */
	protected function get_formated_date( $date ) {
		$format = get_option( 'date_format' ) . ' ' . _x( '\a\t', 'date format', 'notify-users-e-mail' ) . ' ' . get_option( 'time_format' );

		return apply_filters( 'notify_users_email_date_format', date_i18n( $format, strtotime( $date ) ) );
	}

	/**
	 * Apply content placeholders.
	 *
	 * @param  string  $string  String to apply the placeholders.
	 * @param  WP_Post $post    Post/page data.
	 *
	 * @return string           New content.
	 */
	protected function apply_content_placeholders( $string, $post ) {
		$string = str_replace( '{title}', sanitize_text_field( $post->post_title ), $string );
		$string = str_replace( '{link_post}', esc_url( get_permalink( $post->ID ) ), $string );
		$string = str_replace( '{content_post}', apply_filters( 'the_content',get_post_field('post_content', $post->ID)), $string );
		$string = str_replace( '{date}', $this->get_formated_date( $post->post_date ), $string );

		return $string;
	}

	/**
	 * Apply comment placeholders.
	 *
	 * @param  string   $string  String to apply the placehoders.
	 * @param  stdClass $comment Comment data.
	 *
	 * @return string            New content.
	 */
	protected function apply_comment_placeholders( $string, $comment ) {
		$string = str_replace( '{title}', sanitize_text_field( get_the_title( $comment->comment_post_ID ) ), $string );
		$string = str_replace( '{link_comment}', get_comment_link( $comment->comment_ID ), $string );
		$string = str_replace( '{date}', $this->get_formated_date( $comment->comment_date ), $string );

		return $string;
	}

	/**
	 * Create the nofitication email list.
	 *
	 * @param  array  $roles   Roles of users who received the email.
	 * @param  string $send_to List of emails.
	 *
	 * @return array           Email list.
	 */
	protected function notification_list( $roles, $send_to ) {
		$emails = array();

		if ( is_array( $roles ) && ! empty( $roles ) ) {
			// Get emails by user role.
			foreach ( $roles as $role ) {
				// Get the emails.
				$user = new WP_User_Query(
					array(
						'role'   => $role,
						'fields' => array( 'user_email' )
					)
				);
				$user_results = $user->get_results();

				// Add the emails in $mails variable.
				if ( ! empty( $user_results ) ) {
					foreach ( $user_results as $email ) {
						$emails[] = $email->user_email;
					}
				}
			}
		}

		// Merge all emails list.
		$emails = array_unique( array_merge( $emails, explode( ',', $send_to ) ) );

		return $emails;
	}

	/**
	 * Apply body texts.
	 *
	 * @param  string  $text  String to apply the placeholders.
	 * @param  WP_Post $post    Post/page data.
	 *
	 * @return string           New content.
	 * In Development - Not Working
	 */
	public function body_text( $post ) {
		$text = '<p>Head';
		$text .= $this->apply_content_placeholders( $settings['body_post'], $post );
		$text .= 'Body';
		$text .= '</p>';
		$text .= 'Texto do footer';

		return $text;
	}


	/**
	 * Nofity users when publish a post.
	 *
	 * @param  int     $id   Post ID.
	 * @param  WP_Post $post Post data.
	 *
	 * @return void
	 */
	public function send_notification_post( $id, $post ) {
		if ( 'publish' != $post->post_status ) {
 			return;
		}

		if ( empty( $_POST['original_post_status'] ) || 'publish' == $_POST['original_post_status'] ){
			return;
		}

		// Prevent sending twice
		$sent = get_post_meta( $id, '_notify_users_email_sended', true );
		if ( $sent ) {
			return;
		}

		$settings = get_option( 'notify_users_email' );

		if ( ! in_array( $post->post_type, (array) $settings['conditional_post_type'] ) ){
			return;
		}


		$keep_running = false;
		foreach ( $settings as $key => $value ) {
			if ( strrpos( $key, 'conditional_taxonomy_' ) === false ) {
				continue;
			}

			$terms = array_filter( array_unique( array_map( 'absint', array_map( 'trim', $value ) ) ) );

			if ( empty( $terms ) ){
				continue;
			}

			if ( $keep_running === true ){
				continue;
			}

			$taxonomy = str_replace( 'conditional_taxonomy_', '', $key );

			foreach ( $terms as $key => $term ) {
				if ( has_term( $term, $taxonomy, $post ) ){
					$keep_running = true;
				}
			}
		}

		if ( ! $keep_running ) {
			return;
		}

		$emails       = $this->notification_list( $settings['send_to_users'], $settings['send_to'] );
		$subject_post = $this->apply_content_placeholders( $settings['subject_post'], $post );
		$body_post    = $this->apply_content_placeholders( $settings['body_post'], $post );
		$headers 	  = array(
			'Content-Type: text/html; charset=UTF-8',
			'Bcc: ' . implode( ',', $emails )
		);

		// Send the emails.
		if ( apply_filters( 'notify_users_email_use_wp_mail', true ) ) {
			wp_mail( '', $subject_post, $body_post, $headers );
		} else {
			do_action( 'notify_users_email_custom_mail_engine', $emails, $subject_post, $body_post );
		}

		add_post_meta( $id, '_notify_users_email_sended', true );
	}

	/**
	 * Nofity users when publish a comment.
	 *
	 * @param int      $id Comment ID.
	 * @param stdClass $id Comment data.
	 *
	 * @return void
	 */
	public function send_notification_comment( $id, $comment ) {
		$settings        = get_option( 'notify_users_email' );
		$emails          = $this->notification_list( $settings['send_to_users'], $settings['send_to'] );
		$subject_comment = $this->apply_comment_placeholders( $settings['subject_comment'], $comment );
		$body_comment    = $this->apply_comment_placeholders( $settings['body_comment'], $comment );
		$headers 		 = array(
			'Content-Type: text/html; charset=UTF-8',
			'Bcc: ' . implode( ',', $emails )
		);

		// Send the emails.
		if ( apply_filters( 'notify_users_email_use_wp_mail', true ) ) {
			wp_mail( '', $subject_comment, $body_comment, $headers );
		} else {
			do_action( 'notify_users_email_custom_mail_engine', $emails, $subject_comment, $body_comment );
		}
	}
}

/**
 * Register plugin activation.
 */
register_activation_hook( __FILE__, array( 'Notify_Users_EMail', 'activate' ) );

/**
 * Initialize the plugin.
 */
add_action( 'plugins_loaded', array( 'Notify_Users_EMail', 'get_instance' ), 0 );

endif;
