<?php
/**
Plugin Name: Plagiary Search
Plugin Tag: plagiary, plagiarism, copy protection, detection, content
Description: <p>Find websites that copy/paste your content without authorization. </p><p>In addition, you will avoid to include involuntary plagiarism in your articles. </p><p>This plugin is under GPL licence.</p>
Version: 1.2.1
Framework: SL_Framework
Author: SedLex
Author Email: sedlex@sedlex.fr
Framework Email: sedlex@sedlex.fr
Author URI: http://www.sedlex.fr/
Plugin URI: http://wordpress.org/plugins/plagiary-search/
License: GPL3
*/

//Including the framework in order to make the plugin work

require_once('core.php') ; 

/** ====================================================================================================================================================
* This class has to be extended from the pluginSedLex class which is defined in the framework
*/
class plagiary_search extends pluginSedLex {
	
	/** ====================================================================================================================================================
	* Plugin initialization
	* 
	* @return void
	*/
	static $instance = false;

	protected function _init() {
		global $wpdb ; 

		// Name of the plugin (Please modify)
		$this->pluginName = 'Plagiary Search' ; 
		
		// The structure of the SQL table if needed (for instance, 'id_post mediumint(9) NOT NULL, short_url TEXT DEFAULT '', UNIQUE KEY id_post (id_post)') 
		$this->tableSQL = "id mediumint(9) NOT NULL AUTO_INCREMENT, id_post mediumint(9) NOT NULL, url TEXT DEFAULT '', proximity TEXT DEFAULT '', image TEXT DEFAULT '', text1 MEDIUMTEXT DEFAULT '', text2 MEDIUMTEXT DEFAULT '', ignored BOOLEAN NOT NULL DEFAULT 0, authorized BOOLEAN NOT NULL DEFAULT 0, date_maj DATETIME, UNIQUE KEY id (id)" ; 
		// The name of the SQL table (Do no modify except if you know what you do)
		$this->table_name = $wpdb->prefix . "pluginSL_" . get_class() ; 

		//Configuration of callbacks, shortcode, ... (Please modify)
		// For instance, see 
		//	- add_shortcode (http://codex.wordpress.org/Function_Reference/add_shortcode)
		//	- add_action 
		//		- http://codex.wordpress.org/Function_Reference/add_action
		//		- http://codex.wordpress.org/Plugin_API/Action_Reference
		//	- add_filter 
		//		- http://codex.wordpress.org/Function_Reference/add_filter
		//		- http://codex.wordpress.org/Plugin_API/Filter_Reference
		// Be aware that the second argument should be of the form of array($this,"the_function")
		// For instance add_action( "the_content",  array($this,"modify_content")) : this function will call the function 'modify_content' when the content of a post is displayed
		
		// add_action( "the_content",  array($this,"modify_content")) ; 
		add_action( "wp_ajax_notPlagiary",  array($this,"notPlagiary")) ; 
		add_action( "wp_ajax_plagiary",  array($this,"plagiary")) ; 
		add_action( "wp_ajax_notAuthorized",  array($this,"notAuthorized")) ; 
		add_action( "wp_ajax_authorized",  array($this,"authorized")) ; 
		add_action( "wp_ajax_delete_copy",  array($this,"delete_copy")) ; 

		add_action( "wp_ajax_viewText",  array($this,"viewText")) ; 
		
		add_action( "wp_ajax_forceSearchPlagiary",  array($this,"forceSearchPlagiary")) ; 
		add_action( "wp_ajax_stopPlagiary",  array($this,"stopPlagiary")) ; 
		add_action( "wp_ajax_forceSearchSpecificPlagiary",  array($this,"forceSearchSpecificPlagiary")) ; 
		add_action( "wp_ajax_stopSpecificPlagiary",  array($this,"stopSpecificPlagiary")) ; 

		add_action( 'wp_ajax_nopriv_checkIfProcessNeeded', array( $this, 'checkIfProcessNeeded'));
		add_action( 'wp_ajax_checkIfProcessNeeded', array( $this, 'checkIfProcessNeeded'));
		
		// Important variables initialisation (Do not modify)
		$this->path = __FILE__ ; 
		$this->pluginID = get_class() ; 
		
		// activation and deactivation functions (Do not modify)
		register_activation_hook(__FILE__, array($this,'install'));
		register_deactivation_hook(__FILE__, array($this,'deactivate'));
		register_uninstall_hook(__FILE__, array('plagiary_search','uninstall_removedata'));
		
		$this->list_engines = array("google", "bing") ; 
	}
	
	/** ====================================================================================================================================================
	* In order to uninstall the plugin, few things are to be done ... 
	* (do not modify this function)
	* 
	* @return void
	*/
	
	public function uninstall_removedata () {
		global $wpdb ;
		// DELETE OPTIONS
		delete_option('plagiary_search'.'_options') ;
		if (is_multisite()) {
			delete_site_option('plagiary_search'.'_options') ;
		}
		
		// DELETE SQL
		if (function_exists('is_multisite') && is_multisite()){
			$old_blog = $wpdb->blogid;
			$old_prefix = $wpdb->prefix ; 
			// Get all blog ids
			$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM ".$wpdb->blogs));
			foreach ($blogids as $blog_id) {
				switch_to_blog($blog_id);
				$wpdb->query("DROP TABLE ".str_replace($old_prefix, $wpdb->prefix, $wpdb->prefix . "pluginSL_" . 'plagiary_search')) ; 
			}
			switch_to_blog($old_blog);
		} else {
			$wpdb->query("DROP TABLE ".$wpdb->prefix . "pluginSL_" . 'plagiary_search' ) ; 
		}
		
		// DELETE FILES if needed
		SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/plagiary-search/"); 
		$plugins_all = 	get_plugins() ; 
		$nb_SL = 0 ; 	
		foreach($plugins_all as $url => $pa) {
			$info = pluginSedlex::get_plugins_data(WP_PLUGIN_DIR."/".$url);
			if ($info['Framework_Email']=="sedlex@sedlex.fr"){
				$nb_SL++ ; 
			}
		}
		if ($nb_SL==1) {
			SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/"); 
		}

	}

	/**====================================================================================================================================================
	* Function called when the plugin is activated
	* For instance, you can do stuff regarding the update of the format of the database if needed
	* If you do not need this function, you may delete it.
	*
	* @return void
	*/
	
	public function _update() {
		global $wpdb ; 
		SLFramework_Debug::log(get_class(), "Update the plugin." , 4) ; 
				
		// This update aims at adding the authorized fields 
		if ( !$wpdb->get_var("SHOW COLUMNS FROM ".$this->table_name." LIKE 'authorized'")  ) {
			$wpdb->query("ALTER TABLE ".$this->table_name." ADD authorized BOOLEAN NOT NULL DEFAULT 0;");
		}
		
		// This update aims at adding the specific_text fields 
		if ( !$wpdb->get_var("SHOW COLUMNS FROM ".$this->table_name." LIKE 'specific_text'")  ) {
			$wpdb->query("ALTER TABLE ".$this->table_name." ADD specific_text TEXT DEFAULT '';");
		}
		
		// This update aims at adding the specific_sha1 fields 
		if ( !$wpdb->get_var("SHOW COLUMNS FROM ".$this->table_name." LIKE 'specific_sha1'")  ) {
			$wpdb->query("ALTER TABLE ".$this->table_name." ADD specific_sha1 TEXT DEFAULT '';");
		}

	}
	
	/**====================================================================================================================================================
	* Function called to return a number of notification of this plugin
	* This number will be displayed in the admin menu
	*
	* @return int the number of notifications available
	*/
	 
	public function _notify() {
		global $wpdb ; 
		$nb_plagiat = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE ignored = FALSE and  authorized = FALSE ") ; 
		return $nb_plagiat ; 
	}
	
	/** ====================================================================================================================================================
	* Init javascript for the admin side
	* If you want to load a script, please type :
	* 	<code>wp_enqueue_script( 'jsapi', 'https://www.google.com/jsapi');</code> or 
	*	<code>wp_enqueue_script('plagiary_search_script', plugins_url('/script.js', __FILE__));</code>
	*	<code>$this->add_inline_js($js_text);</code>
	*	<code>$this->add_js($js_url_file);</code>
	*
	* @return void
	*/
	
	function _admin_js_load() {	
		return ; 
	}
	
	/** ====================================================================================================================================================
	* Init css for the admin side
	* If you want to load a style sheet, please type :
	*	<code>$this->add_inline_css($css_text);</code>
	*	<code>$this->add_css($css_url_file);</code>
	*
	* @return void
	*/
	
	function _admin_css_load() {	
		return ; 
	}
	
	/** ====================================================================================================================================================
	* Init javascript for the public side
	* If you want to load a script, please type :
	* 	<code>wp_enqueue_script( 'jsapi', 'https://www.google.com/jsapi');</code> or 
	*	<code>wp_enqueue_script('my_plugin_script', plugins_url('/script.js', __FILE__));</code>
	*	<code>$this->add_inline_js($js_text);</code>
	*	<code>$this->add_js($js_url_file);</code>
	*
	* @return void
	*/
	
	function _public_js_load() {	
		ob_start() ; 
		?>
			function checkIfProcessNeeded() {
				
				var arguments = {
					action: 'checkIfProcessNeeded'
				} 
				var ajaxurl2 = "<?php echo admin_url()."admin-ajax.php"?>" ; 
				jQuery.post(ajaxurl2, arguments, function(response) {
					// We do nothing as the process should be as silent as possible
				});    
			}
			
			// We launch the callback
			if (window.attachEvent) {window.attachEvent('onload', checkIfProcessNeeded);}
			else if (window.addEventListener) {window.addEventListener('load', checkIfProcessNeeded, false);}
			else {document.addEventListener('load', checkIfProcessNeeded, false);} 
						
		<?php 
		
		$java = ob_get_clean() ; 
		$this->add_inline_js($java) ; 
	}	
	/** ====================================================================================================================================================
	* Init css for the public side
	* If you want to load a style sheet, please type :
	*	<code>$this->add_inline_css($css_text);</code>
	*	<code>$this->add_css($css_url_file);</code>
	*
	* @return void
	*/
	
	function _public_css_load() {	
		return ; 
	}

	/** ====================================================================================================================================================
	* Called when the content is displayed
	*
	* @param string $content the content which will be displayed
	* @param string $type the type of the article (e.g. post, page, custom_type1, etc.)
	* @param boolean $excerpt if the display is performed during the loop
	* @return string the new content
	*/
	
	function _modify_content($content, $type, $excerpt) {	
		return $content; 
	}
		
	/** ====================================================================================================================================================
	* Add a button in the TinyMCE Editor
	*
	* To add a new button, copy the commented lines a plurality of times (and uncomment them)
	* 
	* @return array of buttons
	*/
	
	function add_tinymce_buttons() {
		$buttons = array() ; 
		//$buttons[] = array(__('title', $this->pluginID), '[tag]', '[/tag]', WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename( __FILE__)).'img/img_button.png') ; 
		return $buttons ; 
	}
	
	/**====================================================================================================================================================
	* Function to instantiate the class and make it a singleton
	* This function is not supposed to be modified or called (the only call is declared at the end of this file)
	*
	* @return void
	*/
	
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	

	
	/** ====================================================================================================================================================
	* Callback for processing
	*
	* @return void
	*/
	
	function checkIfProcessNeeded() {
		$this->process_plagiary(true) ; 
		die() ; 
	}
	
	
	
	/** ====================================================================================================================================================
	* Define the default option values of the plugin
	* This function is called when the $this->get_param function do not find any value fo the given option
	* Please note that the default return value will define the type of input form: if the default return value is a: 
	* 	- string, the input form will be an input text
	*	- integer, the input form will be an input text accepting only integer
	*	- string beggining with a '*', the input form will be a textarea
	* 	- boolean, the input form will be a checkbox 
	* 
	* @param string $option the name of the option
	* @return variant of the option
	*/
	public function get_default_option($option) {
	
		if (is_multisite()) {
			$url = network_home_url();
		} else {
			$url = home_url();
		}
		
		switch ($option) {
			// Alternative default return values (Please modify)
			case 'type_list' 		: return "page,post" 		; break ; 
			case 'min_nb_words' 		: return 200 		; break ; 

			case 'nb_words_of_sentences' : return 7 		; break ; 
			case 'proximity_words_results' : return 50 		; break ; 
			
			case 'exclude' : return "*".$url 		; break ; 
			
			case 'nb_char_prox' : return 70 		; break ; 
			case 'nb_iteration_max' : return 4000 		; break ; 
			
			case 'img_width' : return 150 		; break ; 
			case 'img_height' : return 150 		; break ; 
			case 'max_line_per_iter' : return 50 		; break ; 

			case 'threshold' : return 30 		; break ; 
			case 'equal_proximity' : return 80 		; break ; 
			
			case 'between_two_requests' 		: return 2 		; break ; 
			case 'last_request' 		: return 0 		; break ; 
						
			case 'google' 		: return true 		; break ; 
			case 'google_key' 		: return "" 		; break ;
			case 'google_error_time' 		: return 0 		; break ;
			case 'google_error_msg' 		: return "" 		; break ;
			
			case 'bing' 		: return false 		; break ; 
			case 'bing_key' 		: return "" 		; break ;
			case 'bing_error_time' 		: return 0 		; break ;
			case 'bing_error_msg' 		: return "" 		; break ;
			
			case 'nb_searches' : return 0 		; break ; 
			case 'history_searches' : return array() 		; break ; 
			
			case "nb_char_minidelta" : return 20 ; break;
			
			case "enable_proximity_score_v2" : return true ; break;	
					
			case "send_email_when_found" : return false ; break;			
			case "send_email_when_found_email" : return "" ; break;			

			case 'enable_wkhtmltoimage' : return false ; break ;  
			case 'enable_wkhtmltoimage_winw' : return 1024 ; break ;  
		}
		return null ;
	}

	/** ====================================================================================================================================================
	* The admin configuration page
	* This function will be called when you select the plugin in the admin backend 
	*
	* @return void
	*/
	
	public function configuration_page() {
		global $wpdb;
		global $blog_id ; 
				
		SLFramework_Debug::log(get_class(), "Print the configuration page." , 4) ; 
		
		?>
		<div class="plugin-titleSL">
			<h2><?php echo $this->pluginName ?></h2>
		</div>
		
		<div class="plugin-contentSL">		
			<?php echo $this->signature ; ?>

			<?php
			//===============================================================================================
			// After this comment, you may modify whatever you want
			
			// We check rights
			$this->check_folder_rights( array(array(WP_CONTENT_DIR."/sedlex/test/", "rwx")) ) ;
			
			$tabs = new SLFramework_Tabs() ; 
			
			ob_start() ; 
				
				echo "<div id='plagiaryZone'>" ; 
				$this->displayPlagiary() ; 
				echo "</div>" ; 
				echo "<div id='plagiaryPopup'>" ; 
				echo "</div>" ; 

			$tabs->add_tab(__('Possible Plagiaries',  $this->pluginID), ob_get_clean()) ; 	

			ob_start() ; 
				
				echo "<div>" ; 
				$this->displayPlagiaryIgnored() ; 
				echo "</div>" ;  

			$tabs->add_tab(__('Ignored/False Positive',  $this->pluginID), ob_get_clean()) ; 	
			
			ob_start() ; 
				
				echo "<div>" ; 
				$this->displayPlagiaryAuthorized() ; 
				echo "</div>" ;  

			$tabs->add_tab(__('Authorized Copies',  $this->pluginID), ob_get_clean()) ; 	

			ob_start() ; 
				
				echo "<div id='currentSearchZone'>" ; 
				$this->displayCurrentSearch() ; 
				echo "</div>" ; 

			$tabs->add_tab(__('Search Status',  $this->pluginID), ob_get_clean()) ; 	

			ob_start() ; 
				
				echo "<div id='specificSearchZone'>" ; 
				$this->retrieve_param_specific() ; 
				$this->displaySpecificSearch() ; 
				echo "</div>" ; 

			$tabs->add_tab(__('Specific Search',  $this->pluginID), ob_get_clean()) ; 	
			
			ob_start() ; 
				
				echo "<div>" ; 
				$this->displayCurrentSearch_summary() ; 
				echo "</div>" ; 

			$tabs->add_tab(__('Summary',  $this->pluginID), ob_get_clean()) ; 	

			// HOW To
			ob_start() ;
				echo "<p>".__("With this plugin, you may find external websites that copy-and-paste your content without authorization.", $this->pluginID)."</p>" ; 
				echo "<p>".__("In addition, you will avoid to include involuntary plagiarism in your articles.", $this->pluginID)."</p>" ; 
			$howto1 = new SLFramework_Box (__("Purpose of that plugin", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".__("There is two different ways to look for plagiaries:", $this->pluginID)."</p>" ; 
				echo "<ul style='list-style-type: disc;padding-left:40px;'>" ; 
					echo "<li><p>".__("an automatic process (namely background process):", $this->pluginID)."</p></li>" ; 
						echo "<ul style='list-style-type: circle;padding-left:40px;'>" ; 
							echo "<li><p>".__("Every time a user visits a page of the frontside of your website, a small portion of the search process is performed;", $this->pluginID)."</p></li>" ; 
							echo "<li><p>".__("Note that if you have very few visits, the searches may be quite long.", $this->pluginID)."</p></li>" ; 
						echo "</ul>" ;
					echo "<li><p>".__("a forced process:", $this->pluginID)."</p></li>" ; 
						echo "<ul style='list-style-type: circle;padding-left:40px;'>" ; 
							echo "<li><p>".__("The button that triggers this forced process may be found in the Search Status tab;", $this->pluginID)."</p></li>" ; 
							echo "<li><p>".__("You have to stay on that page to force the processing: if you go on another page (or if you reload the page), the process will be stopped.", $this->pluginID)."</p></li>" ; 
						echo "</ul>" ;				
				echo "</ul>" ; 
			$howto2 = new SLFramework_Box (__("How to search for plagiary?", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".__("To search for a page that is a plagiary, different steps are executed:", $this->pluginID)."</p>" ; 
				echo "<ul style='list-style-type: disc;padding-left:40px;'>" ; 
					echo "<li><p>".__("a page/post is randomly selected (see the first block in the parameter tab);", $this->pluginID)."</p></li>" ; 
					echo "<li><p>".__("a sentence of that page/post is selected (see the second block in the parameter tab);", $this->pluginID)."</p></li>" ; 
					echo "<li><p>".__("this sentence is searched on the Internet (see the third block in the parameter tab);", $this->pluginID)."</p></li>" ; 
					echo "<li><p>".__("if the result of this search is close to the search sentence (see the fourth block in the parameter tab), retrieve the content of the external page and compare it with the content of your page/post (see the fifth block in the parameter tab);", $this->pluginID)."</p></li>" ; 		
					echo "<li><p>".__("if the comparison shows that the external page is very similar to your page/post, the plugin may send you an email (see the sixth block in the parameter tab) and generate an image representing the plagiary (see the seventh block in the parameter tab).", $this->pluginID)."</p></li>" ; 		
				echo "</ul>" ; 
			$howto3 = new SLFramework_Box (__("How to search process works?", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".__('The plagiary image is a set of grey pixels.', $this->pluginID)."</p>" ; 
				echo "<p>".__('In fact, your text is represented in the X-axis and the external page is represented in the Y-Axis.', $this->pluginID)."</p>" ; 
				echo "<p><img src='".WP_PLUGIN_URL."/".str_replace(basename(__FILE__),"",plugin_basename( __FILE__))."img/plagiarism.png'></p>" ; 
				echo "<p>".__('Each pixel (x,y) represent a correlation (i.e. proximity) between a sentence of your post at a given position x and a sentence of the external page at another given position y.', $this->pluginID)."</p>" ; 
				echo "<p>".__('The darker this pixel is, the more correlation the sentences have.', $this->pluginID)."</p>" ; 
				echo "<p>".__('Most of the time, if you have a oblic line in the image, it means that a part of your post has been reproduced (or that you reproduce this part :) ).', $this->pluginID)."</p>" ; 
			$howto4 = new SLFramework_Box (__("How to interpret the plagiary image", $this->pluginID), ob_get_clean()) ; 

			ob_start() ;
				 echo $howto1->flush() ; 
				 echo $howto2->flush() ; 
				 echo $howto3->flush() ; 
				 echo $howto4->flush() ; 
			$tabs->add_tab(__('How To',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_how.png") ; 	
							
			ob_start() ; 
				$params = new SLFramework_Parameters($this, "tab-parameters") ; 
				
				$params->add_title(__('Select your content to be searched',  $this->pluginID)) ; 
				$params->add_param('type_list', __('What type of articles do you want to search?',  $this->pluginID)) ; 
				$params->add_comment(__("The default value is:",  $this->pluginID)) ; 
				$params->add_comment_default_value('type_list') ; 
				$params->add_param('min_nb_words', __('The minimum number of words that the article contains:',  $this->pluginID)) ; 
				
				$params->add_title(__('Select a sentence in the content',  $this->pluginID)) ; 
				$params->add_param('nb_words_of_sentences', __('Exclude from the search sentences shorter than (nb words):',  $this->pluginID)) ; 

				$params->add_title(__('Search your sentence',  $this->pluginID)) ; 
				$params->add_param('google', "<img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/google.png"."'/> ".__('Use the Google Search:',  $this->pluginID), "", "", array('google_key')) ;
				$params->add_param('google_key', __('Google API key:',  $this->pluginID)) ; 
				$params->add_comment(__("If you do not set any key, you might not be able to search with Google.",  $this->pluginID)) ; 
				$params->add_comment(__("If you set a key, only 100 searches are allowed per day (according to Google terms).",  $this->pluginID)) ; 
				$params->add_comment(sprintf(__("To get this API key, please visit %s, create a projet, allow Custom Search API in services, and then go to API console to get a 'Key for browser apps'",  $this->pluginID), "<a href='https://code.google.com/apis/console'>Google API Console</a>")) ; 
				$params->add_param('bing', "<img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/bing.png"."'/> ".__('Use the Bing Search:',  $this->pluginID), "", "", array('bing_key')) ;
				$params->add_param('bing_key', __('Bing API Account key:',  $this->pluginID)) ; 
				$params->add_comment(__("If you do not set any key, you might not be able to search with Bing.",  $this->pluginID)) ; 
				$params->add_comment(sprintf(__("To get this API key, please visit %s, and subscribe to the free search. Then, go to your account and copy your primary account key number.",  $this->pluginID), "<a href='http://datamarket.azure.com/dataset/bing/search'>Azure Market</a>")) ; 
				
				$params->add_title(__('Quick filter of the found results',  $this->pluginID)) ; 
				$params->add_param('proximity_words_results', __('Exclude from the results if the searched sentence matches less than (% words):',  $this->pluginID)) ; 
				
				$params->add_title(__('Deep filter of the found results',  $this->pluginID)) ; 
				$params->add_param('equal_proximity', __('Consider that a sentence is a plagiary if it contains more than (% of identical letters) :',  $this->pluginID)) ;
				$params->add_comment(__("This value should be between 10 and 90.",  $this->pluginID)) ; 
				$params->add_param('threshold', __('Exclude results that plagiarize less than (% of your content):',  $this->pluginID)) ;
				$params->add_comment(__("The higher this value is, the less results you will have.",  $this->pluginID)) ; 
				$params->add_comment(__("This value should be between 10 and 90.",  $this->pluginID)) ; 
				$params->add_param('exclude', __('Exclude results with this pattern:',  $this->pluginID)) ;
				$params->add_comment(__("Please enter one entry per line",  $this->pluginID)) ; 
				$params->add_param('enable_proximity_score_v2', __('Use the proximity score v2 algorithm:',  $this->pluginID)) ;
				$params->add_comment(__("This algorithm try to enhance the identification of plagiaries that reproduce a plurality of consecutive sentences of your post/page.",  $this->pluginID)) ; 
				
				$params->add_title(__('Warn when plagiaray found',  $this->pluginID)) ; 
				$params->add_param('send_email_when_found', __('Send an email when a plagiary is found.',  $this->pluginID), "", "", array("send_email_when_found_email")) ;
				$params->add_param('send_email_when_found_email', __('Your email that should be used',  $this->pluginID)) ;

				$params->add_title(__('Display results',  $this->pluginID)) ; 
				$params->add_param('img_width', __('Height of the proximity image:',  $this->pluginID)) ;
				$params->add_param('img_height', __('Width of the proximity image:',  $this->pluginID)) ;
				
				$params->add_title(__('Avanced options',  $this->pluginID)) ; 
				$params->add_param('nb_char_prox', __('Number of characters to compare per iteration:',  $this->pluginID)) ;
				$params->add_param('nb_iteration_max', __('Max number of iterations per turn:',  $this->pluginID)) ;
				$params->add_param('max_line_per_iter', __('Max number of line per turn:',  $this->pluginID)) ;
				$params->add_param('between_two_requests', __('Number of minutes between two background processing:',  $this->pluginID)) ;
				$params->add_param('nb_char_minidelta', __('Number of characters for the mini delta:',  $this->pluginID)) ;
				
				
				$upload_dir = wp_upload_dir() ;
				$command_wk =  $upload_dir['basedir']."/sedlex/wkhtmltox/bin/wkhtmltoimage" ; 

				$params->add_title(__('Screen capture',  $this->pluginID)) ; 
				$params->add_param('enable_wkhtmltoimage', sprintf(__('Use the executable %s:',  $this->pluginID), "<a href='http://wkhtmltopdf.org/'>Wkhtmltoimage</a>")) ; 				
				$params->add_comment(sprintf(__('To use this option, you should download the binary of %s compatible with your system and install it in %s',  $this->pluginID), "<a href='http://wkhtmltopdf.org/'>Wkhtmltoimage</a>", "<code>$command_wk</code>")) ; 
				if (is_file($command_wk)) {
					$res = $this->wkurltoimage('http://www.google.com/', 1024, 100, 100) ; 
					if ($res['success']) {
						$params->add_comment(sprintf(__('If you see an image here, it mean it works %s',  $this->pluginID), "<img src='".$res["thumb"]."'>")) ; 
					} else {
						$params->add_comment(sprintf(__('There is a problem with the installation: %s',  $this->pluginID), "<code>".$res["msg"]."</code>")) ; 
					}
				} else {
					$params->add_comment(sprintf(__('For now, it appears that the file %s does not exist. This option cannot work if activated.',  $this->pluginID), "<code>$command_wk</code>")) ; 
				}
			
				$params->flush() ; 
				
			$tabs->add_tab(__('Parameters',  $this->pluginID), ob_get_clean() , WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_param.png") ; 	
			
			$frmk = new coreSLframework() ;  
			if (((is_multisite())&&($blog_id == 1))||(!is_multisite())||($frmk->get_param('global_allow_translation_by_blogs'))) {
				ob_start() ; 
					$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
					$trans = new SLFramework_Translation($this->pluginID, $plugin) ; 
					$trans->enable_translation() ; 
				$tabs->add_tab(__('Manage translations',  $this->pluginID), ob_get_clean() , WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_trad.png") ; 	
			}

			ob_start() ; 
				
				echo "<h3>".__('Information', $this->pluginID)."</h3>" ; 
				echo "<p style='color:#999999;'>".__('Please note that, without doing anything, a background ckeck is performed.', $this->pluginID)."</p>" ; 
				echo "<p style='color:#999999;'>".__('This background ckeck is triggered when users visits your site: thus, if you have very little traffic on your website, it could take hours to compare two texts. Just be patient :)', $this->pluginID)."</p>" ; 
				echo "<p style='color:#999999;'>".__('If you want to accelerate the process, you may click on the force button (see below). Please note that it may quickly consume all your search quota (Google, etc.)', $this->pluginID)."</p>" ; 
				echo "<p style='color:#999999;'>".__('When forcing the process, errors may occur. If too many errors are raised, please reduce parameters.', $this->pluginID)."</p>" ; 
				echo "<h3>".__('How to read the plagiary image?', $this->pluginID)."</h3>" ; 
				echo "<p>".__('The image is a representation of the proximity between your article and an external website.', $this->pluginID)."</p>" ; 
				echo "<p>".__('Your article is represented on the horizontal axis and the external website on the vertical axis.', $this->pluginID)."</p>" ; 
				echo "<p style=''><img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/howtoread.png"."'/></p>" ; 
				echo "<p>".__('The darker of a pixel indicates the proximity between a part of your article and a part of the external website.', $this->pluginID)."</p>" ; 
				echo "<p>".__('Then, to find an actual plagiarism, you need to find a straight and dark line in the proximity image.', $this->pluginID)."</p>" ; 
				echo "<p style=''><img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/howtoread2.png"."'/></p>" ; 

				
			$tabs->add_tab(__('FAQ',  $this->pluginID), ob_get_clean()) ; 	

			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new SLFramework_Feedback($plugin, $this->pluginID) ; 
				$trans->enable_feedback() ; 
			$tabs->add_tab(__('Give feedback',  $this->pluginID), ob_get_clean() , WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_mail.png") ; 	
			
			ob_start() ; 
				// A list of plugin slug to be excluded
				$exlude = array('wp-pirate-search') ; 
				// Replace sedLex by your own author name
				$trans = new SLFramework_OtherPlugins ("sedLex", $exlude) ; 
				$trans->list_plugins() ; 
			$tabs->add_tab(__('Other plugins',  $this->pluginID), ob_get_clean() , WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_plug.png") ; 	
			
			echo $tabs->flush() ; 
			
			
			// Before this comment, you may modify whatever you want
			//===============================================================================================
			?>
			<?php echo $this->signature ; ?>
		</div>
		<?php
	}
	
	
	/** ====================================================================================================================================================
	* Get an image based on url
	*
	* @return void
	*/

	function wkurltoimage($url, $winw=0, $cropw=0, $croph=0) {
		global $blog_id ; 
	
		// We create the folder for the img files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
		
		if (!is_dir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold)) {
			@mkdir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold, 0777, true) ; 
		}
		
		$upload_dir = wp_upload_dir() ;
		$command_wk =  $upload_dir['basedir']."/sedlex/wkhtmltox/bin/wkhtmltoimage" ; 
		$path_img = WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($url.$winw).".jpg" ; 
		$url_img = WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($url.$winw).".jpg" ;

		$path_th = WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($url.$winw).".jpg" ; 
		$url_th = WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($url.$winw).".jpg" ;
		
		$path_log = WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($url.$winw).".log" ; 
		
		if (is_file($path_img)) {
			@unlink($path_img) ; 
		}
		if (is_file($path_log)) {
			@unlink($path_log) ; 
		}

		if (is_file($command_wk)){
			$additional_cmd = "" ; 
			if ($winw!=0) {
				$additional_cmd .= " --width ".$winw ; 
			}
			if ($cropw==0) {
				$cropw = 200 ; 
			}
			if ($croph==0) {
				$croph = 200 ; 
			}
			// Local file for wkhtmltoimage
			$command = $command_wk.$additional_cmd." ".$url." ".$path_img ; 
			$str = exec($command, $output, $return) ; 
			
			// on teste l'existence du fichier
			if (is_file($path_img)) {
			    list($width, $height) = getimagesize($path_img);
			    $myImage = imagecreatefromjpeg($path_img);
				
				// Si le ratio de largeur est plus grand que le ratio de hauteur, 
				// cela signifie que la contrainte sera la largeur
				if ($cropw/$width > $croph/$height) {
				  $x = 0;
				  $origin_w = $width;
				  $origin_h = $croph*$width/$cropw ;
				// Sinon, 
				// cela signifie que la contrainte sera la hauteur
				} else {
				  $origin_w = $cropw*$height/$croph ;
				  $origin_h = $height;
				  $x = ($width-$origin_w)/2;
				}

				// copying the part into thumbnail
				
				$thumb = imagecreatetruecolor($cropw, $croph);
				imagecopyresampled($thumb, $myImage, 0, 0, $x, 0, $cropw, $croph, $origin_w, $origin_h);

				if (imagejpeg ($thumb, $path_th, 92)) {
					return array("success"=>true, "url"=>$url_img, "thumb"=>$url_th) ; 
				} else {
					@file_put_contents($path_log, sprintf(__("%s has failed.", $this->pluginID), "imagejpeg")) ; 
					return array("success"=>false, "msg"=>sprintf(__("%s has failed.", $this->pluginID), "imagejpeg")) ; 
				}
				
			} else { 
				@file_put_contents($path_log, sprintf(__("%s has failed: %s.", $this->pluginID), "<code>".$command_wk."</code>", "<code>".implode(" - ",$output)."</code>")) ; 
				return array("success"=>false, "msg"=>sprintf(__("%s has failed: %s.", $this->pluginID), "<code>".$command_wk."</code>", "<code>".implode(" - ",$output)."</code>")) ; 
			}
		} else {
			@file_put_contents($path_log, sprintf(__("%s does not exist.", $this->pluginID), "<code>".$command_wk."</code>")) ; 
			return array("success"=>false, "msg"=>sprintf(__("%s does not exist.", $this->pluginID), "<code>".$command_wk."</code>")) ; 
		}
	}
	
	/** =====================================================================================================
	* Clean a text to be able to compare it
	*
	* @return string
	*/
	
	function clean_text($text){
	
		$result = $text ; 
		
		$attr_value = '(?:"(?:\\.|[^"]+)*"|\'(?:\\.|[^\\\']*)\'|\S*)';
		$attrs = "(?:\s+\w[-\w]*(?:\={$attr_value})?)*";
		
		$attrs2 = "[^>]*" ; 
	
		$toFind = 	array(	'/^.*<body'.$attrs2.'\s*>/i',
						"@<h[0-9]".$attrs2."\s*>(.*?)</h[0-9]>@Ssi", 
						"@<!--".$attrs2."-->@Ssi", 
						"@<script".$attrs2.">.*?</script>@Ssi", 
						"@<style".$attrs2.">.*?</style>@Ssi", 
						"@<blockquote".$attrs2.">.*?</blockquote>@Ssi", 
						"@\n[\s.]*\n@Ssi"
				) ; 
		$toReplace = array(	'', 
						". \1. ", 
						"",
						"",
						"",
						"",
						"\n"
				) ; 
				
		$result = preg_replace($toFind, $toReplace, $result);
		
		$result= str_replace('</p>',".</p>",$result);
		$result= str_replace('<br/>',".<br/>",$result);
		$result= str_replace('<br>',".<br>",$result);
		
		$result = strip_tags($result) ; 
		
		$result= str_replace('&#8220;','"',$result); // http://www.utexas.edu/learn/html/spchar.html
		$result= str_replace('&#8221;','"',$result);
		$result= str_replace('&#8221;','"',$result);
		$result= str_replace('&#171;','"',$result);
		$result= str_replace('&#187;','"',$result);
		$result= str_replace('&#8216;',"'",$result);
		$result= str_replace('&#8217;',"'",$result);
		$result= str_replace('"'," ",$result);
		$result= str_replace("'"," ",$result);
		$result= str_replace(","," ",$result);
		$result= str_replace("("," ",$result);
		$result= str_replace(")"," ",$result);
		$result= str_replace("]"," ",$result);
		$result= str_replace("["," ",$result);
		$result= str_replace(":"," ",$result);
		
		
		
		$result= html_entity_decode($result,ENT_QUOTES,"UTF-8"); #NOTE: UTF-8 does not work!
		
		$result= preg_replace('/&#(\d+);/me',"chr(\\1)",$result); #decimal notation
		$result= preg_replace('/&#x([a-f0-9]+);/mei',"chr(0x\\1)",$result);  #hex notation
		
		$result = strtolower($result)  ;
		
		$result = str_replace("\n", ' ', $result) ; 
		$result = str_replace("\r", ' ', $result) ; 
		$result = trim($result) ; 
		
		$result= str_replace(";",".",$result);
		$result= str_replace("!",".",$result);
		$result= str_replace("?",".",$result);
		
		$result = trim($result);
		
		$result = str_replace(" .", ".", $result);

		return $result ;
	} 
	
	/** =====================================================================================================
	* Retrieve parameters
	*
	* @return void
	*/
	
	function retrieve_param(){
		global $blog_id ; 
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		if (!is_dir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold)) {
			@mkdir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold, 0777, true) ; 	
			$this->param = array("next_step"=>"get_text_to_search") ; 
			return ; 
		}
		// On trouve le bon fichier
		$files = @scandir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold) ; 
		foreach ($files as $f) {
			if (preg_match("/^plagiary.*txt/i", $f)) {
				$content = @unserialize(@file_get_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold.$f)) ; 
				if (is_array($content)) {
					$this->param = $content ; 
					return ; 
				} else {
					$this->param = array("next_step"=>"get_text_to_search") ; 
					return ; 
				}
			}
		}
		$this->param = array("next_step"=>"get_text_to_search") ; 
		return ; 
	}
	
	/** =====================================================================================================
	* Retrieve parameters specific
	*
	* @return void
	*/
	
	function retrieve_param_specific(){
		global $blog_id ; 
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		if (!is_dir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold)) {
			@mkdir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold, 0777, true) ; 	
			$this->param_specific = array("next_step"=>"get_text_to_search") ; 
			return ; 
		}
		// On trouve le bon fichier
		$files = @scandir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold) ; 
		foreach ($files as $f) {
			if (preg_match("/^specific.*txt/i", $f)) {
				$content = @unserialize(@file_get_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold.$f)) ; 
				if (is_array($content)) {
					$this->param_specific = $content ; 
					return ; 
				} else {
					$this->param_specific = array("next_step"=>"get_text_to_search") ; 
					return ; 
				}
			}
		}
		$this->param_specific = array("next_step"=>"get_text_to_search") ; 
		return ; 
	}
	
	/** =====================================================================================================
	* Store Parameters
	*
	* @return void
	*/
	
	function store_param(){

		global $blog_id ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		if (!is_dir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold)) {
			@mkdir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold, 0777, true) ; 
		}
		// On cherche le bon fichier
		$files = @scandir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold) ; 
		$found = false ; 
		foreach ($files as $f) {
			if (preg_match("/^plagiary.*txt/i", $f)) {
				$found = $f ; 
			}
		}
		
		if ($found==false) {
			$found = "plagiary_".sha1(rand(0,100000000).WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold).".txt" ; 
		}
		
		file_put_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold.$found, serialize($this->param)) ; 
		
		return ; 
	}
	
	/** =====================================================================================================
	* Store Parameters specific
	*
	* @return void
	*/
	
	function store_param_specific(){

		global $blog_id ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		if (!is_dir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold)) {
			@mkdir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold, 0777, true) ; 
		}
		// On cherche le bon fichier
		$files = @scandir(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold) ; 
		$found = false ; 
		foreach ($files as $f) {
			if (preg_match("/^specific.*txt/i", $f)) {
				$found = $f ; 
			}
		}
		
		if ($found==false) {
			$found = "specific_".sha1(rand(0,100000000).WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold).".txt" ; 
		}
		
		file_put_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold.$found, serialize($this->param_specific)) ; 
		
		return ; 
	}
	/** ====================================================================================================================================================
	* Display the status of the current Search and show the buffer ...
	*
	* @return void
	*/
	
	function displayCurrentSearch() {

		ob_start() ; 
			echo "<h3>".__('Force the searches', $this->pluginID)."</h3>" ; 
			echo "<p>" ; 
			echo "<input type='button' id='plagiaryButton' class='button-primary validButton' onClick='forceSearchPlagiary()'  value='". __('Force a search for plagiarism',$this->pluginID)."' />" ; 
			echo "&nbsp;<input type='button' id='stopSearchButton' class='button validButton' onClick='stopSearchPlagiary()'  value='". __('Stop the forced search',$this->pluginID)."' />" ; 
			echo "</p><p>" ; 
			echo "<input type='button' id='stopButton' class='button-primary validButton' onClick='stopPlagiary()'  value='". __('Reset all searches and empty the search buffer',$this->pluginID)."' />" ; 
			echo "<script>jQuery('#plagiaryButton').removeAttr('disabled');</script>" ; 
			echo "<script>jQuery('#stopButton').removeAttr('disabled');</script>" ; 
			echo "<img id='wait_process' src='".WP_PLUGIN_URL."/".str_replace(basename(__FILE__),"",plugin_basename( __FILE__))."core/img/ajax-loader.gif' style='display: none;'>" ; 
			echo "</p>" ; 


			echo "<div id='detail_currentSearch'>" ; 
			echo $this->displayCurrentSearch_detail() ; 
			echo "</div>" ; 		

		$box = new SLFramework_Box(__('Status of the current search', $this->pluginID), ob_get_clean()) ; 
		echo $box->flush() ; 
	}
	
		/** ====================================================================================================================================================
	* Display the status of the current Search and show the buffer ...
	*
	* @return void
	*/
	
	function displaySpecificSearch() {

		ob_start() ; 
			echo "<h3>".__('Look for plagiaries of a specific text', $this->pluginID)."</h3>" ; 
			if (isset($this->param_specific['text'])) {
				echo "<textarea id='specificSearch_text' style='height:300px; width:100%'>".$this->param_specific['text']."</textarea>" ; 
			} else {
				echo "<textarea id='specificSearch_text' style='height:300px; width:100%'>".__('Your text to search', $this->pluginID)."</textarea>" ; 
			}			
			echo "<p>" ; 
			echo "<input type='button' id='specificplagiaryButton' class='button-primary validButton' onClick='forceSearchSpecificPlagiary()'  value='". __('Search for plagiarism of the above text',$this->pluginID)."' />" ; 
			echo "&nbsp;<input type='button' id='specificstopSearchButton' class='button validButton' onClick='stopSearchSpecificPlagiary()'  value='". __('Stop the search',$this->pluginID)."' />" ; 
			echo "</p><p>" ; 
			echo "<input type='button' id='specificstopButton' class='button-primary validButton' onClick='stopSpecificPlagiary()'  value='". __('Reset',$this->pluginID)."' />" ; 
			echo "<script>jQuery('#specificplagiaryButton').removeAttr('disabled');</script>" ; 
			echo "<script>jQuery('#specificstopButton').removeAttr('disabled');</script>" ; 
			echo "<img id='wait_specificprocess' src='".WP_PLUGIN_URL."/".str_replace(basename(__FILE__),"",plugin_basename( __FILE__))."core/img/ajax-loader.gif' style='display: none;'>" ; 
			echo "</p>" ; 


			echo "<div id='detail_specificSearch'>" ; 
			echo $this->displaySpecificSearch_detail() ; 
			echo "</div>" ; 		

		$box = new SLFramework_Box(__('Status of the specific search', $this->pluginID), ob_get_clean()) ; 
		echo $box->flush() ; 
	}
	
	/** ====================================================================================================================================================
	* Display the status of the current Search and show the buffer ...
	*
	* @return void
	*/
	
	function displayCurrentSearch_summary() {
		global $wpdb ; 
		ob_start() ; 
			echo "<h3>".__('Global Synthesis', $this->pluginID)."</h3>" ; 
			$nb_plagiat = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE ignored=FALSE") ; 
			echo "<p>".sprintf(__("For now, %s URL have been checked for plagiarism and %s possible plagiaries have been found.", $this->pluginID), $this->get_param('nb_searches'), $nb_plagiat)."</p>" ; 
			echo "<p>".sprintf(__("The last time a check has been performed is %s.", $this->pluginID), date_i18n("d/m/Y H:i:s", $this->get_param('last_request'), true))."</p>" ; 
			
			echo "<h3>".__('History Synthesis', $this->pluginID)."</h3>" ; 
						
			// Creating the graph for the Google
			//----------------------------------

			$rows = "" ; 
			$history = $this->get_param('history_searches') ; 
			
			$first = true ; 
			$nb = 0 ; 
			$last_persons = "0" ; 
			$last_visits = "0" ; 
			
			$width = "900" ; 
			$height = "400" ; 
			
			$colors = "['#FF9999', '#5CB8E6', '#003399', '#5E5556', '#49584B', '#72705A', '#807374', '#5E5556', '#55475E', '#2F2C47']" ; 
			$first = true ; 
			for ($i=0 ; $i<30 ; $i++) {
				$d = date("Ymd", time()-60*60*24*$i) ; 
				if (!$first) $rows .= "," ; 
				$date = date("d/m/Y", time()-60*60*24*$i) ; 
				if (isset($history[$d])) {
					$a = $history[$d] ; 
				} else {
					$a = array("search_enough_word"=>0, "search_not_enough_word"=>0, "compare_text"=>0, ) ; 
				}
				$search_enough_word = $a["search_enough_word"] ; 
				if (!is_numeric($search_enough_word)) $search_enough_word = 0 ; 
				$search_not_enough_word = $a["search_not_enough_word"] ; 
				if (!is_numeric($search_not_enough_word)) $search_not_enough_word = 0 ; 
				$compare_text = $a["compare_text"] ; 
				if (!is_numeric($compare_text)) $compare_text = 0 ; 
				$rows .= "['".$date."', ".$search_enough_word .", ".$search_not_enough_word.", ".$compare_text."]" ; 
				$first = false ; 
				$nb++ ; 
			}
			?>
			<div id="google_plagiary_count" style="margin: 0px auto; width:<?php echo $width; ?>px; height:<?php echo $height; ?>px;"></div>
			<script  type="text/javascript">
				google.setOnLoadCallback(CountVisits);
				google.load('visualization', '1', {'packages':['corechart']});
				
				function CountVisits() {
					var data = new google.visualization.DataTable();
					data.addColumn('string', '<?php echo __('Month', $this->pluginID)?>');
					data.addColumn('number', '<?php echo __('Number of Found and Excluded Pages', $this->pluginID)?>');
					data.addColumn('number', '<?php echo __('Number of Found and Non-Excluded Pages', $this->pluginID)?>');
					data.addColumn('number', '<?php echo __('Number of Deeply Analyzed Pages', $this->pluginID)?>');
					data.addRows([<?php echo $rows ; ?>]);
					var options = {
						width: <?php echo $width ; ?>, 
						height: <?php echo $height ; ?>,
						colors:<?php echo $colors ?>,
						title: '<?php echo __("Analyzed pages", $this->pluginID) ?>',
						hAxis: {title: '<?php echo __('Time Line', $this->pluginID)?>'}
					};

					var chart = new google.visualization.ColumnChart(document.getElementById('google_plagiary_count'));
					chart.draw(data, options);
				}
			</script>
			<?php
		$box = new SLFramework_Box(__('Summary of plagiary searches', $this->pluginID), ob_get_clean()) ; 
		echo $box->flush() ; 
	}
	
	/** ====================================================================================================================================================
	* Display the status of the current Search and show the buffer (detail)
	*
	* @return void
	*/
	
	function displayCurrentSearch_detail() {
		global $wpdb ; 
		if (!isset($this->param)) {
			$this->retrieve_param() ; 
		}
		
		echo "<h3>".__('Current Status', $this->pluginID)."</h3>" ; 
		
		$nb_plagiat = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE ignored=FALSE") ; 
		echo "<p>".sprintf(__("For now, %s URL have been checked for plagiarism and %s possible plagiaries have been found.", $this->pluginID), $this->get_param('nb_searches'), $nb_plagiat)."</p>" ; 
		
		$nb_step = 7 ; 
		
		// 1
		if ($this->param['next_step']=="get_text_to_search") {
			$status = __("Select a new article", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, 0, __("Init", $this->pluginID)) ; 
		// 2
		} else if ($this->param['next_step']=="search_web_engines") {
			if ((isset($this->param['list_search_engine']))&&(count($this->param['list_search_engine'])!=0)) {
				$percen_val = min(100,ceil(100*($this->param['list_search_engine_index']+1)/count($this->param['list_search_engine']))) ; 
				$percentage = $percen_val."%" ; 
				if (isset($this->param['list_search_engine'][$this->param['list_search_engine_index']])) {
					$search_engine = $this->param['list_search_engine'][$this->param['list_search_engine_index']] ; 
				} else {
					$search_engine = __('(End)', $this->pluginID) ; 
				}
			} else {
				$percen_val = 0 ; 
				$percentage = $percen_val."%" ; 
				$search_engine = "?" ; 
			} 
			$status = sprintf(__("Search a sentence on the Search Engines (%s): %s", $this->pluginID), $percentage, "\"<i>".$this->param['searched_sentence'] ."</i>\"") ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(0+1/$nb_step*$percen_val), sprintf(__("Search %s", $this->pluginID), ucfirst($search_engine))) ; 
		// 3
		} else if ($this->param['next_step']=="retrieve_content_of_url") {
			$status = __("Get the content of the website", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(1/$nb_step*100), "1/$nb_step") ; 
		// 4
		} else if ($this->param['next_step']=="compare_text") {
			if (isset($this->param['nb_current_iterations'])) {
				$percen_val = min(100,ceil(100*$this->param['nb_current_iterations']/$this->param['nb_total_iterations'])) ; 
				$percentage = $percen_val."%" ; 
			} else {
				$percen_val = 0 ; 
				$percentage = $percen_val."%" ; 
			} 
			$status = sprintf(__("Compare article and website (%s)", $this->pluginID), $percentage) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(2/$nb_step*100+1/$nb_step*$percen_val), "2/$nb_step") ; 
		// 5
		} else if ($this->param['next_step']=="filter_results") {
			if (isset($this->param['filter_index'])) {
				$index_val = min(100,ceil($this->param["filter_index"]/$this->get_param("img_width")*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Filter results (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(3/$nb_step*100+1/$nb_step*$index_val), "3/$nb_step") ; 
		// 6
		} else if ($this->param['next_step']=="compute_proximity_images") {
			if (isset($this->param['image_index'])) {
				$index_val = min(100,ceil($this->param["image_index"]/$this->get_param("img_width")*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Create the proximity image (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(4/$nb_step*100+1/$nb_step*$index_val), "4/$nb_step") ; 
		// 7
		} else if ($this->param['next_step']=="text_format") {
			if (isset($this->param['format_index1'])) {
				$index_val = min(100,ceil(($this->param["format_index1"]+$this->param["format_index2"])/($this->get_param("img_width")+$this->get_param("img_height"))*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Format the texts to be correctly displayed (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(5/$nb_step*100+1/$nb_step*$index_val), "5/$nb_step") ; 
		// 7
		} else if ($this->param['next_step']=="store_result") {
			$status = __("Store results in the database", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(6/$nb_step*100), "6/$nb_step") ; 
		// 8
		} else if ($this->param['next_step']=="stop") {
			$status = __("End of the current plagiarism check", $this->pluginID) ;
			
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(7/$nb_step*100), "7/7") ;
		//
		} else if ($this->param['next_step']=="error") {
			$status = sprintf(__("Error", $this->pluginID)) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(8/8*100), __("Error", $this->pluginID)) ; 
		} else if ($this->param['next_step']=="wait_error") {
			$status = sprintf(__("Error", $this->pluginID)) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(8/8*100), sprintf(__("Waiting for %s minutes", $this->pluginID), "".(ceil(($this->get_param('last_request')-time())/60)))) ; 
		} else {
			$status = __("??", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(0/8*100), __("??", $this->pluginID)) ; 
		}
	
		
		echo "<p><b>".sprintf(__('Status: %s', $this->pluginID),"</b>".$status)."</p>" ; 
		echo "<p>" ; 
		echo $progress_status->flush() ; 
		echo "</p>" ; 

		// Information
		if (!isset($this->param['information'])) {
			echo "<p><b>".sprintf(__('Information: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Information: %s', $this->pluginID),"</b> ".$this->param['information'])."</p>" ; 		
		}
		// Error
		if (!isset($this->param['warning'])) {
			echo "<p><b>".sprintf(__('Warning: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Warning: %s', $this->pluginID),"</b> <span style='color:#FF9933'>".$this->param['warning'])."</span></p>" ; 		
		}
		// Error
		if (!isset($this->param['error'])) {
			echo "<p><b>".sprintf(__('Error: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Error: %s', $this->pluginID),"</b> <span style='color:#E60000'>".$this->param['error'])."<span></p>" ; 		
		}
		
		echo "<h3>".__('Article and website page', $this->pluginID)."</h3>" ; 
		// Article
		if (isset($this->param["id"])) {
			$thepost = get_post($this->param["id"]); 
			$content = "<a href='".get_permalink($this->param["id"])."'>".$thepost->post_title."</a>" ;
			echo "<p><b>".sprintf(__('Selected article: %s (%s characters)', $this->pluginID), "</b>".$content, mb_strlen($this->param["text"]))."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Selected article: %s', $this->pluginID), "</b>".__('No article is being analyzed', $this->pluginID))."</p>" ; 
		}
		
		// Phrase
		if (isset($this->param['searched_sentence'])) {
			$sentence = $this->param["searched_sentence"] ; 
			if (mb_strlen($sentence)>150) {
				$sentence = mb_substr($sentence, 0, 150)."..." ; 
			}
			echo "<p><b>".sprintf(__('Sentence in the article searched on Search Engine: %s', $this->pluginID),"</b> \"<i>".$sentence."</i>\"")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Sentence in the article searched on Search Engine: %s', $this->pluginID),"</b><i>".__('None for now', $this->pluginID))."</i></p>" ; 		
		}
		
		// External website
		$content = "<i>".__('None for now', $this->pluginID)."</i>" ; 
		if (isset($this->param["url"])) {
			$url = $this->param["url"] ; 
			if (mb_strlen($url)>70) {
				$url = mb_substr($url, 0, 70)."..." ; 
			}
			$content = "<a href='".$this->param["url"]."'>".$url."</a>" ;
			
		}
		if (isset($this->param["content"])) {
			$content .= " ".sprintf(__('(%s characters)', $this->pluginID), mb_strlen($this->param["content"])) ; 
		}
		
		echo "<p><b>".sprintf(__('Website to compare with: %s', $this->pluginID),"</b>".$content)."</p>" ; 
		
		echo "<h3>".__('Search buffer', $this->pluginID)."</h3>" ; 
		
		if ((isset($this->param["url_buffer"]))&&(count($this->param["url_buffer"])!=0)) {
			echo "<p>".__('The next couple of [article-website] to be compared are:', $this->pluginID)."</p>" ; 
			echo "<ul>" ; 
			for ($i = count($this->param["url_buffer"])-1 ; $i>max(-1,count($this->param["url_buffer"])-1-5) ; $i--) {
				if (isset($this->param["url_buffer"][$i])) {
					$url = $this->param["url_buffer"][$i][0] ; 
					if (mb_strlen($url)>70) {
						$url = mb_substr($url, 0, 70)."..." ; 
					}
					
					echo "<li>" ;
					$thepost = get_post($this->param["id"]); 
					foreach ($this->list_engines as $engine) {
						if ($this->param["url_buffer"][$i][2]==$engine) {
							echo "<img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/".$engine.".png"."'/> " ; 
						} 
					}
					echo "<a href='".get_permalink($this->param["url_buffer"][$i][1])."'>".$thepost->post_title."</a>" ;
					echo " &gt; " ;
					echo "<a href='".$this->param["url_buffer"][$i][0]."'>".$url."</a> "."(".$this->param["url_buffer"][$i][3].")" ;
					echo "</li>" ;
				}
			}
			echo "</ul>" ; 
			if (count($this->param["url_buffer"])>5) {
				echo "<p>".sprintf(__('%s more website are present in the search buffer but are not listed here.', $this->pluginID), count($this->param["url_buffer"])-5)."</p>" ; 
			}
		} else {
			echo "<p>".__('No url present in the buffer...', $this->pluginID)."</p>" ; 
		}	
		
		echo "<h3>".__('Status of the search engines', $this->pluginID)."</h3>" ; 
		
		foreach ($this->list_engines as $engine) {
			if ($this->get_param($engine)) {
				if (trim($this->get_param($engine.'_error_msg'))!="") {
					// check if an error occurs less than 30 min ago on this search engine
					if (time()>$this->get_param($engine.'_error_time')+30*60) { 
						$status = $this->get_param($engine.'_error_msg') ; 
					} else {
						$status =  $this->get_param($engine.'_error_msg')." (".sprintf(__("Wait for %s seconds", $this->pluginID), max(0,(30*60-(time()-$this->get_param($engine.'_error_time'))))."").")" ; 
					}
				} else {
					$status = __("OK", $this->pluginID) ; 
				}
				echo "<p><b>".ucfirst($engine)."</b>: ".$status."</p>" ; 
			}
		}
	}
	
	/** ====================================================================================================================================================
	* Display the status of the specific Search and show the buffer (detail)
	*
	* @return void
	*/
	
	function displaySpecificSearch_detail() {
		global $wpdb ; 
		if (!isset($this->param_specific)) {
			$this->retrieve_param_specific() ; 
		}
		
		echo "<h3>".__('Current Status', $this->pluginID)."</h3>" ; 
		
		if (isset($this->param_specific['num_sentence'])) {
			$num = $this->param_specific['num_sentence'] ;
		} else {
			$num = 0 ; 
			$this->param_specific['num_sentence'] = 0 ;
		} 
		if (isset( $this->param_specific['text'])) {
			$text = $this->param_specific['text'] ;
		} else {
			$text = "" ; 
			$this->param_specific['text'] = "" ; 
		}
		
		$percentage = floor($num/count(explode(".", $text))*100) ; 
		$progress_status_sentence = new SLFramework_Progressbar  (500, 20, $percentage, sprintf(__("Text: %s", $this->pluginID),$percentage."%")) ; 
		
		$nb_step = 7 ; 
		
		// 1
		if ($this->param_specific['next_step']=="get_text_to_search") {
			$status = __("Get a sentence", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, 0, __("Init", $this->pluginID)) ; 
		// 2
		} else if ($this->param_specific['next_step']=="search_web_engines") {
			if ((isset($this->param_specific['list_search_engine']))&&(count($this->param_specific['list_search_engine'])!=0)) {
				$percen_val = min(100,ceil(100*($this->param_specific['list_search_engine_index']+1)/count($this->param_specific['list_search_engine']))) ; 
				$percentage = $percen_val."%" ; 
				if (isset($this->param_specific['list_search_engine'][$this->param_specific['list_search_engine_index']])) {
					$search_engine = $this->param_specific['list_search_engine'][$this->param_specific['list_search_engine_index']] ; 
				} else {
					$search_engine = __('(End)', $this->pluginID) ; 
				}
			} else {
				$percen_val = 0 ; 
				$percentage = $percen_val."%" ; 
				$search_engine = "?" ; 
			} 
			
			$status = sprintf(__("Search the sentence on the Search Engines (%s): %s", $this->pluginID), $percentage, "\"<i>".$this->param_specific['searched_sentence'] ."</i>\"") ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(0+1/$nb_step*$percen_val), sprintf(__("Search %s", $this->pluginID), ucfirst($search_engine))) ; 
		// 3
		} else if ($this->param_specific['next_step']=="retrieve_content_of_url") {
			$status = __("Get the content of the website", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(1/$nb_step*100), "1/$nb_step") ; 
		// 4
		} else if ($this->param_specific['next_step']=="compare_text") {
			if (isset($this->param_specific['nb_current_iterations'])) {
				$percen_val = min(100,ceil(100*$this->param_specific['nb_current_iterations']/$this->param_specific['nb_total_iterations'])) ; 
				$percentage = $percen_val."%" ; 
			} else {
				$percen_val = 0 ; 
				$percentage = $percen_val."%" ; 
			} 
			$status = sprintf(__("Compare article and website (%s)", $this->pluginID), $percentage) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(2/$nb_step*100+1/$nb_step*$percen_val), "2/$nb_step") ; 
		// 5
		} else if ($this->param_specific['next_step']=="filter_results") {
			if (isset($this->param_specific['filter_index'])) {
				$index_val = min(100,ceil($this->param_specific["filter_index"]/$this->get_param("img_width")*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Filter results (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(3/$nb_step*100+1/$nb_step*$index_val), "3/$nb_step") ; 
		// 6
		} else if ($this->param_specific['next_step']=="compute_proximity_images") {
			if (isset($this->param_specific['image_index'])) {
				$index_val = min(100,ceil($this->param_specific["image_index"]/$this->get_param("img_width")*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Create the proximity image (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(4/$nb_step*100+1/$nb_step*$index_val), "4/$nb_step") ; 
		// 7
		} else if ($this->param_specific['next_step']=="text_format") {
			if (isset($this->param_specific['format_index1'])) {
				$index_val = min(100,ceil(($this->param_specific["format_index1"]+$this->param_specific["format_index2"])/($this->get_param("img_width")+$this->get_param("img_height"))*100)) ; 
				$index = $index_val."%" ; 
			} else {
				$index_val = 0 ; 
				$index = $index_val."%" ; 
			}
			$status = sprintf(__("Format the texts to be correctly displayed (%s)", $this->pluginID), $index) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(5/$nb_step*100+1/$nb_step*$index_val), "5/$nb_step") ; 
		// 7
		} else if ($this->param_specific['next_step']=="store_result") {
			$status = __("Store results in the database", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(6/$nb_step*100), "6/$nb_step") ; 
		// 8
		} else if ($this->param_specific['next_step']=="stop") {
			$status = __("End of the current plagiarism check", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(7/$nb_step*100), "7/7") ; 
		//
		} else if ($this->param_specific['next_step']=="end") {
			$status = __("End of the current plagiarism check", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(7/$nb_step*100), "7/7") ; 
			echo "<p>-End-</p>" ;  
		//
		} else if ($this->param_specific['next_step']=="error") {
			$status = sprintf(__("Error", $this->pluginID)) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(8/8*100), __("Error", $this->pluginID)) ; 
			echo "<p>-End-</p>" ;  
		} else if ($this->param_specific['next_step']=="wait_error") {
			$status = sprintf(__("Error", $this->pluginID)) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(8/8*100), sprintf(__("Waiting for %s minutes", $this->pluginID), "".(ceil(($this->get_param('last_request')-time())/60)))) ; 
			echo "<p>-End-</p>" ;  
		} else {
			$status = __("??", $this->pluginID) ;
			$progress_status = new SLFramework_Progressbar  (500, 20, ceil(0/8*100), __("??", $this->pluginID)) ; 
			echo "<p>-End-</p>" ;  
		}
	
		
		echo "<p><b>".sprintf(__('Status: %s', $this->pluginID),"</b>".$status)."</p>" ; 
		echo "<p>" ; 
		echo $progress_status->flush() ; 
		echo "</p>" ; 
		echo "<p>" ; 
		echo $progress_status_sentence->flush() ; 
		echo "</p>" ; 

		// Information
		if (!isset($this->param_specific['information'])) {
			echo "<p><b>".sprintf(__('Information: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Information: %s', $this->pluginID),"</b> ".$this->param_specific['information'])."</p>" ; 		
		}
		// Error
		if (!isset($this->param_specific['warning'])) {
			echo "<p><b>".sprintf(__('Warning: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Warning: %s', $this->pluginID),"</b> <span style='color:#FF9933'>".$this->param_specific['warning'])."</span></p>" ; 		
		}
		// Error
		if (!isset($this->param_specific['error'])) {
			echo "<p><b>".sprintf(__('Error: %s', $this->pluginID),"</b> <i>".__('None', $this->pluginID)."</i>")."</p>" ; 
		} else {
			echo "<p><b>".sprintf(__('Error: %s', $this->pluginID),"</b> <span style='color:#E60000'>".$this->param_specific['error'])."<span></p>" ; 		
		}
		
		echo "<h3>".__('Website page', $this->pluginID)."</h3>" ; 
		
		// External website
		$content = "<i>".__('None for now', $this->pluginID)."</i>" ; 
		if (isset($this->param_specific["url"])) {
			$url = $this->param_specific["url"] ; 
			if (mb_strlen($url)>70) {
				$url = mb_substr($url, 0, 70)."..." ; 
			}
			$content = "<a href='".$this->param_specific["url"]."'>".$url."</a>" ;
			
		}
		if (isset($this->param_specific["content"])) {
			$content .= " ".sprintf(__('(%s characters)', $this->pluginID), mb_strlen($this->param_specific["content"])) ; 
		}
		
		echo "<p><b>".sprintf(__('Website to compare with: %s', $this->pluginID),"</b>".$content)."</p>" ; 
		
		echo "<h3>".__('Search buffer', $this->pluginID)."</h3>" ; 
		
		if ((isset($this->param_specific["url_buffer"]))&&(count($this->param_specific["url_buffer"])!=0)) {
			echo "<p>".sprintf(__('The next contents (%s) to be compared are:', $this->pluginID), count($this->param_specific["url_buffer"]))."</p>" ; 
			echo "<ul>" ; 
			for ($i = count($this->param_specific["url_buffer"])-1 ; $i>max(-1,count($this->param_specific["url_buffer"])-1-5) ; $i--) {
				if (isset($this->param_specific["url_buffer"][$i])) {
					$url = $this->param_specific["url_buffer"][$i][0] ; 
					if (mb_strlen($url)>70) {
						$url = mb_substr($url, 0, 70)."..." ; 
					}
					
					echo "<li>" ;
					foreach ($this->list_engines as $engine) {
						if ($this->param_specific["url_buffer"][$i][2]==$engine) {
							echo "<img src='".WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."img/".$engine.".png"."'/> " ; 
						} 
					}
					echo "<a href='".$this->param_specific["url_buffer"][$i][0]."'>".$url."</a> "."(".$this->param_specific["url_buffer"][$i][3].")" ;
					echo "</li>" ;
				}
			}
			echo "</ul>" ; 
			if (count($this->param_specific["url_buffer"])>5) {
				echo "<p>".sprintf(__('%s more website are present in the search buffer but are not listed here.', $this->pluginID), count($this->param_specific["url_buffer"])-5)."</p>" ; 
			}
		} else {
			echo "<p>".__('No url present in the buffer...', $this->pluginID)."</p>" ; 
		}	
		
		echo "<h3>".__('Status of the search engines', $this->pluginID)."</h3>" ; 
		
		foreach ($this->list_engines as $engine) {
			if ($this->get_param($engine)) {
				if (trim($this->get_param($engine.'_error_msg'))!="") {
					// check if an error occurs less than 30 min ago on this search engine
					if (time()>$this->get_param($engine.'_error_time')+30*60) { 
						$status = $this->get_param($engine.'_error_msg') ; 
					} else {
						$status =  $this->get_param($engine.'_error_msg')." (".sprintf(__("Wait for %s seconds", $this->pluginID), max(0,(30*60-(time()-$this->get_param($engine.'_error_time'))))."").")" ; 
					}
				} else {
					$status = __("OK", $this->pluginID) ; 
				}
				echo "<p><b>".ucfirst($engine)."</b>: ".$status."</p>" ; 
			}
		}
		
		$this->displayPlagiary(sha1($this->param_specific['text'])) ; 
	}

	/** ====================================================================================================================================================
	* Stop plagiary search
	*
	* @return void
	*/
	
	function stopPlagiary() {
		$this->param = array('next_step' => 'stop', 'information' => __('Reset of the search', $this->pluginID)) ; 
		$this->store_param() ; 
		$this->displayCurrentSearch() ; 
		die() ;
	}
	
	/** ====================================================================================================================================================
	* Stop plagiary search
	*
	* @return void
	*/
	
	function stopSpecificPlagiary() {
		$this->param_specific = array('next_step' => 'stop', 'information' => __('Reset of the search', $this->pluginID)) ; 
		$this->store_param_specific() ; 
		$this->displaySpecificSearch() ; 
		die() ;
	}

	/** ====================================================================================================================================================
	* Create a table which summarize all the found / possible plagiaries
	*
	* @return void
	*/
	
	function displayPlagiary($sha1='') {
		global $blog_id ; 
		global $wpdb ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
		
		$table = new SLFramework_Table() ;
		$table->title(array(__('Possible Plagiary',  $this->pluginID),__('Article Plagiarized',  $this->pluginID), __('Proximity Image',  $this->pluginID), __('Proximity Score',  $this->pluginID), __('Date of detection',  $this->pluginID)) ) ;
		
		$sha1_where = "" ; 
		if ($sha1!='') {
			$sha1_where = " AND specific_sha1='".$sha1."'" ; 
			echo sprintf(__("Only for specific text with this SHA1: %s", $this->pluginID), "<code>".$sha1."</code>") ; 
		}
		
		$select = "SELECT * FROM ".$this->table_name." WHERE ignored=FALSE and authorized=FALSE".$sha1_where ; 
		$results = $wpdb->get_results($select) ; 
		
		$nb = 0 ; 
		 
		foreach ($results as $r) {
		
			$add_img = "" ; 
			
			if ($this->get_param('enable_wkhtmltoimage')) {
			
				if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) {
					$add_img .= '<p style="text-align:center"><a target="_blank" href="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'"><img src="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'" /></a></p>' ; 
				} else {
					if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")) {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("%s have generated an error while trying to generate the thumbnail: %s", $this->pluginID), "<code>wkHtmlToImage</code>", file_get_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")).'</p>' ; 
					} else {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("Thumbnail has not been generated by %s", $this->pluginID), "<code>wkHtmlToImage</code>").'</p>' ; 
					}
				}
			}
			
			$cel1 = new adminCell("<p><a href='".$r->url."'>".$r->url."</a><p>".$add_img) ;
			$cel1->add_action(__("Not plagiary", $this->pluginID), "notPlagiary('".$r->id."', '".addslashes(__("Do you confirm that this entry is not a plagiary?", $this->pluginID))."')") ; 
			$cel1->add_action(__("Authorized copy", $this->pluginID), "authorized('".$r->id."', '".addslashes(__("Do you confirm that this entry is an authorized copy?", $this->pluginID))."')") ; 
			$cel1->add_action(__("Plagiary Deleted", $this->pluginID), "delete_copy('".$r->id."', '".addslashes(__("Do you confirm that this plagiary has been deleted?", $this->pluginID))."')") ; 
			$cel1->add_action(__("View the texts", $this->pluginID), "viewText('".$r->id."')") ; 
			if ($r->id_post!=-1) {
				$thepost = get_post($r->id_post); 
				$cel2 = new adminCell("<p><a href='".get_permalink($r->id_post)."'>".$thepost->post_title."</a><p>") ;
			} else {
				$cel2 = new adminCell("<p style='font-size:75%'>".__("Performed for a specific text. No related to any post.", $this->pluginID)."</p><p style='font-size:75%;color:#AAAAAA;'>".mb_substr($r->specific_text, 0, 200)."</p>") ;
			}
			$cel3 = new adminCell("<p><img src='".WP_CONTENT_URL.$r->image."'><p>") ;
			$cel4 = new adminCell("<p>".$r->proximity."<p>") ;
			$cel5 = new adminCell("<p id='date".$r->id."'>".$r->date_maj."<p>") ;
			
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), $r->id."") ;
			
			$nb++ ; 
		}
		
		if ($nb==0) {
			$cel1 = new adminCell("<p>".__('(For now, there is no plagiary found... You should wait until something is found)',  $this->pluginID)."</p>") ;
			$cel2 = new adminCell("") ;
			$cel3 = new adminCell("") ;
			$cel4 = new adminCell("") ;
			$cel5 = new adminCell("") ;
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), '1') ;
			$nb++ ; 			
		}

		echo $table->flush() ;
	}

	/** ====================================================================================================================================================
	* Create a table which summarize all the plagiaries being ignored
	*
	* @return void
	*/
	
	function displayPlagiaryIgnored() {
		global $blog_id ; 
		global $wpdb ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
		
		$table = new SLFramework_Table() ;
		$table->title(array(__('Ignored Plagiary',  $this->pluginID),__('Article Plagiarized',  $this->pluginID), __('Proximity Image',  $this->pluginID), __('Proximity Score',  $this->pluginID), __('Date of detection',  $this->pluginID)) ) ;
		
		$select = "SELECT * FROM ".$this->table_name." WHERE ignored=TRUE and authorized=FALSE" ; 
		$results = $wpdb->get_results($select) ; 
		
		$nb = 0 ; 
		 
		foreach ($results as $r) {
		
			$add_img = "" ; 
			if ($this->get_param('enable_wkhtmltoimage')) {
				if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) {
					$add_img .= '<p style="text-align:center"><a target="_blank" href="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'"><img src="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'" /></a></p>' ; 
				} else {
					if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")) {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("%s have generated an error while trying to generate the thumbnail: %s", $this->pluginID), "<code>wkHtmlToImage</code>", file_get_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")).'</p>' ; 
					} else {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("Thumbnail has not been generated by %s", $this->pluginID), "<code>wkHtmlToImage</code>").'</p>' ; 
					}
				}
			}

			$cel1 = new adminCell("<p><a href='".$r->url."'>".$r->url."</a><p>".$add_img) ;
			$cel1->add_action(__("Plagiary", $this->pluginID), "plagiary('".$r->id."', '".addslashes(__("Do you confirm that this entry is a plagiary?", $this->pluginID))."')") ; 
			$cel1->add_action(__("Authorized copy", $this->pluginID), "authorized('".$r->id."', '".addslashes(__("Do you confirm that this entry is an authorized copy?", $this->pluginID))."')") ; 
			$cel1->add_action(__("Plagiary Deleted", $this->pluginID), "delete_copy('".$r->id."', '".addslashes(__("Do you confirm that this plagiary has been deleted?", $this->pluginID))."')") ; 
			$cel1->add_action(__("View the texts", $this->pluginID), "viewText('".$r->id."')") ; 
			$thepost = get_post($r->id_post); 
			$cel2 = new adminCell("<p><a href='".get_permalink($r->id_post)."'>".$thepost->post_title."</a><p>") ;
			$cel3 = new adminCell("<p><img src='".WP_CONTENT_URL.$r->image."'><p>") ;
			$cel4 = new adminCell("<p>".$r->proximity."<p>") ;
			$cel5 = new adminCell("<p id='date".$r->id."'>".$r->date_maj."<p>") ;
			
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), $r->id."") ;
			
			$nb++ ; 
		}
		
		if ($nb==0) {
			$cel1 = new adminCell("<p>".__('(For now, there is no ignored plagiary...)',  $this->pluginID)."</p>") ;
			$cel2 = new adminCell("") ;
			$cel3 = new adminCell("") ;
			$cel4 = new adminCell("") ;
			$cel5 = new adminCell("") ;
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), '1') ;
			$nb++ ; 			
		}

		echo $table->flush() ;
	}
	
	/** ====================================================================================================================================================
	* Create a table which summarize all the authorized copy
	*
	* @return void
	*/
	
	function displayPlagiaryAuthorized() {
		global $blog_id ; 
		global $wpdb ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
		
		$table = new SLFramework_Table() ;
		$table->title(array(__('Authorized Copy',  $this->pluginID),__('Article Plagiarized',  $this->pluginID), __('Proximity Image',  $this->pluginID), __('Proximity Score',  $this->pluginID), __('Date of detection',  $this->pluginID)) ) ;
		
		$select = "SELECT * FROM ".$this->table_name." WHERE authorized=TRUE" ; 
		$results = $wpdb->get_results($select) ; 
		
		$nb = 0 ; 
		 
		foreach ($results as $r) {
		
			$add_img = "" ; 
			if ($this->get_param('enable_wkhtmltoimage')) {
				if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) {
					$add_img .= '<p style="text-align:center"><a target="_blank" href="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'"><img src="'.WP_CONTENT_URL."/sedlex/plagiary-search/".$blog_fold."wk_th_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".jpg".'" /></a></p>' ; 
				} else {
					if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")) {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("%s have generated an error while trying to generate the thumbnail: %s", $this->pluginID), "<code>wkHtmlToImage</code>", file_get_contents(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($r->url.$this->get_param('enable_wkhtmltoimage_winw')).".log")).'</p>' ; 
					} else {
						$add_img .= '<p style="text-align:center; color:#999999;">'.sprintf(__("Thumbnail has not been generated by %s", $this->pluginID), "<code>wkHtmlToImage</code>").'</p>' ; 
					}
				}
			}

			$cel1 = new adminCell("<p><a href='".$r->url."'>".$r->url."</a><p>".$add_img) ;
			$cel1->add_action(__("Not Authorized", $this->pluginID), "notAuthorized('".$r->id."', '".addslashes(__("Do you confirm that this entry is an non-authorized copy?", $this->pluginID))."')") ; 
			$cel1->add_action(__("Plagiary Deleted", $this->pluginID), "delete_copy('".$r->id."', '".addslashes(__("Do you confirm that this plagiary has been deleted?", $this->pluginID))."')") ; 
			$cel1->add_action(__("View the texts", $this->pluginID), "viewText('".$r->id."')") ; 
			$thepost = get_post($r->id_post); 
			$cel2 = new adminCell("<p><a href='".get_permalink($r->id_post)."'>".$thepost->post_title."</a><p>") ;
			$cel3 = new adminCell("<p><img src='".WP_CONTENT_URL.$r->image."'><p>") ;
			$cel4 = new adminCell("<p>".$r->proximity."<p>") ;
			$cel5 = new adminCell("<p id='date".$r->id."'>".$r->date_maj."<p>") ;
			
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), $r->id."") ;
			
			$nb++ ; 
		}
		
		if ($nb==0) {
			$cel1 = new adminCell("<p>".__('(For now, there is no authorized plagiary...)',  $this->pluginID)."</p>") ;
			$cel2 = new adminCell("") ;
			$cel3 = new adminCell("") ;
			$cel4 = new adminCell("") ;
			$cel5 = new adminCell("") ;
			$table->add_line(array($cel1, $cel2, $cel3, $cel4, $cel5), '1') ;
			$nb++ ; 			
		}

		echo $table->flush() ;
	}
	
	/** =====================================================================================================
	* Force plagiary search
	*
	* @return string
	*/
	
	function forceSearchPlagiary(){
		$this->process_plagiary() ; 
		$this->displayCurrentSearch_detail() ; 
		die() ; 
	}
	
	/** =====================================================================================================
	* Force specific plagiary search
	*
	* @return string
	*/
	
	function forceSearchSpecificPlagiary(){
		$textToAnalyzed = $this->clean_text($_POST['text']) ;
		 
		$this->process_plagiary_specific($textToAnalyzed) ; 
		$this->displaySpecificSearch_detail() ; 
		die() ; 
	}
				
	/** =====================================================================================================
	* Process the plagiary search
	*
	* @return string
	*/
	
	function process_plagiary($tempo=false){
		// We check that the last request has not been emitted since a too short period of time
		$now = time() ; 
		if ($tempo) {
			$last = $this->get_param('last_request') ; 
			if ($now-$last<=60*$this->get_param('between_two_requests')) {
				echo sprintf(__('Only %s seconds since the last computation: please wait!', $this->pluginID), ($now-$last)."" ) ; 
				return ; 
			}
		}
		$this->set_param('last_request',$now); 
		
		if (!isset($this->param)) {
			$this->retrieve_param() ; 
		}
			
		unset($this->param['information']) ; 
		unset($this->param['warning']) ; 
		unset($this->param['error']) ; 
		
		if ($this->param['next_step']=="get_text_to_search") {
			$this->get_text_to_search() ; 
		} else if ($this->param['next_step']=="search_web_engines") {
			$this->search_web_engines() ; 
		} else if ($this->param['next_step']=="retrieve_content_of_url") {
			$this->retrieve_content_of_url() ; 	
		} else if ($this->param['next_step']=="compare_text") {
			$this->compare_text() ; 
		} else if ($this->param['next_step']=="filter_results") {
			$this->filter_results() ; 
		} else if ($this->param['next_step']=="compute_proximity_images") {
			$this->compute_proximity_images() ; 
		} else if ($this->param['next_step']=="text_format") {
			$this->text_format() ; 
		} else if ($this->param['next_step']=="store_result") {
			$this->store_result() ; 
		} else if ($this->param['next_step']=="stop") {
			$this->stop() ; 
		} else if ($this->param['next_step']=="error") {	
			// If there is an error, we wait 30 minutes to avoid any ban
			if ($tempo==true) {
				$this->set_param('last_request',$now+60*30); 
				$this->param['next_step'] = "wait_error" ; 
			}
		} else if ($this->param['next_step']=="wait_error") {
			$this->stop() ; 
		}
				
		$this->store_param() ; 
		
		// we re-authorize a new request
		$this->set_param('last_request', time()) ; 
	}
	
	/** =====================================================================================================
	* Process the specific plagiary search
	*
	* @return string
	*/
	
	function process_plagiary_specific($text){
				
		if (!isset($this->param_specific)) {
			$this->retrieve_param_specific() ; 
		}
			
		unset($this->param_specific['information']) ; 
		unset($this->param_specific['warning']) ; 
		unset($this->param_specific['error']) ; 
		
		// Si le text a change on reinitialise
		if ((!isset($this->param_specific['text']))||($text!=$this->param_specific['text'])) {
			$this->param_specific['text'] = $text ; 
			$this->param_specific['num_sentence'] = 0 ; 
			$this->param_specific['next_step']="get_text_to_search" ; 
		}
		
		if ($this->param_specific['next_step']=="get_text_to_search") {
			$this->get_text_to_search_specific() ; 
		} else if ($this->param_specific['next_step']=="search_web_engines") {
			$this->search_web_engines_specific() ; 
		} else if ($this->param_specific['next_step']=="retrieve_content_of_url") {
			$this->retrieve_content_of_url_specific() ; 	
		} else if ($this->param_specific['next_step']=="compare_text") {
			$this->compare_text_specific() ; 
		} else if ($this->param_specific['next_step']=="filter_results") {
			$this->filter_results_specific() ; 
		} else if ($this->param_specific['next_step']=="compute_proximity_images") {
			$this->compute_proximity_images_specific() ; 
		} else if ($this->param_specific['next_step']=="text_format") {
			$this->text_format_specific() ; 
		} else if ($this->param_specific['next_step']=="store_result") {
			$this->store_result_specific() ; 
		} else if ($this->param_specific['next_step']=="stop") {
			$this->stop_specific() ; 
		} else if ($this->param_specific['next_step']=="error") {	
			
		} else if ($this->param_specific['next_step']=="wait_error") {
			$this->stop_specific() ; 
		}
						
		$this->store_param_specific() ; 

	}
	
	/** =====================================================================================================
	* step 1 of the process : Select the text to be searched
	*
	* @return string
	*/
	
	function get_text_to_search($text=""){
		global $post ; 
		if ($text=="") {
			$ok = false ; 
			$iter = 0 ; 
			while (!$ok) {
				// We get a random post
				$args = array(
					'numberposts'     => 1,
					'orderby'         => 'rand',
					'post_type'       => explode(",",$this->get_param('type_list')),
					'post_status'     => 'publish' );
					
				$myQuery = new WP_Query( $args ); 

				//Looping through the posts
				$post_temp = array() ; 
				while ( $myQuery->have_posts() ) {
					$myQuery->the_post();
					$post_temp[] = $post;
				}
				wp_reset_postdata();
				
				$text = $post_temp[0]->post_content ; 
				$iter++ ; 
				if (($this->get_param('min_nb_words')<=count(explode(' ', $text))) || ($iter > 30)) {
					$ok=true ; 
				}
			}
		}
		
		$text = $this->clean_text($text) ; 
		
		// We get a random sentence
		$sentences = explode(".", $text) ; 
		
		$random_sentence = trim($sentences[rand(0, count($sentences) - 1)]) ; 
		$nb_it_max = 30 ; 
		while (count(explode(" ",$random_sentence))<$this->get_param('nb_words_of_sentences')) {
			$random_sentence = trim($sentences[rand(0, count($sentences) - 1)]) ; 
			$nb_it_max -- ; 
			if ($nb_it_max<=0) {
				break ; 
			}
		}
		
		// return
		$this->param = array("next_step"=>"search_web_engines", "id"=>$post_temp[0]->ID, "text"=>$text) ; 
		$this->param['searched_sentence'] = $random_sentence ; 
		$this->param['information'] = mb_strlen($post_temp[0]->post_content) ; 
	}
	
	/** =====================================================================================================
	* specific step 1 of the process : Select the text to be searched
	*
	* @return string
	*/
	
	function get_text_to_search_specific(){		
		
		// We get a random sentence
		$text = $this->param_specific['text'] ; 
		$sentences = explode(".", $text) ; 
		
		$sentence = trim($sentences[$this->param_specific['num_sentence']]) ; 
		
		$this->param_specific['num_sentence'] ++ ; 
		
		// return
		$this->param_specific["next_step"] = "search_web_engines" ; 
		$this->param_specific['searched_sentence'] = $sentence ; 
		$this->param_specific['list_search_engine_index'] = -1 ; 
		$this->param_specific['list_search_engine'] = array() ; 
		foreach ($this->list_engines as $engine) {
			if ($this->get_param($engine)) {
				$this->param_specific['list_search_engine'][] = $engine ; 
			}
		}
		if ($this->param_specific['num_sentence']==1) {
			$this->param_specific['url_buffer'] = array() ; 
			$this->param_specific['at_least_one_engine'] = false ;
		} 
		
		$this->param_specific['information'] = sprintf(__("The text is %s character long. The sentence %s is being analyzed."),mb_strlen($this->param_specific['text']), $this->param_specific['num_sentence']) ; 	
	}	
	
	/** =====================================================================================================
	* step 2 of the process : Search a random sentence of the selected text on Web Engine
	*
	* @return string
	*/
	
	function search_web_engines(){
		
	
		// Bufferize the list of configured search engine
		if (!isset($this->param['list_search_engine'])) {
			$this->param['at_least_one_engine'] = false ; 
			$this->param['url_buffer'] = array() ; 
			$this->param['list_search_engine_index'] = 0 ; 
			foreach ($this->list_engines as $engine) {
				if ($this->get_param($engine)) {
					$this->param['list_search_engine'][] = $engine ; 
				}
			}
		} else {
			$this->param['list_search_engine_index']++ ; 
		}
		
		// No search engine have been configured
		if (!isset($this->param['list_search_engine'])) {
			$this->param['error'] = __("No search engine has been configured. Please configure at least one in the configuration tab.", $this->pluginID) ; 
			return ; 
		}
		
		// All search engine have been used
		if (count($this->param['list_search_engine'])<=$this->param['list_search_engine_index']-1) {
			// if there is an issue with the search engine we wait ...
			if ($this->param['at_least_one_engine']==false) {
				unset ($this->param['list_search_engine']) ; 
				return ; 
			} else {
				if (count($this->param['url_buffer'])==0) {
					$this->param = array('next_step' => 'stop', 'warning' => __("Search engines have not returned any acceptable result.", $this->pluginID) ) ; 
					return ; 
				} else {
					$this->pop_buffer() ; 
					$this->param['next_step'] = 'retrieve_content_of_url' ; 
					return ; 
				}
			}
		}
		
		// continue to search
		if (isset($this->param['list_search_engine'][$this->param['list_search_engine_index']])) {
			$next_engine = $this->param['list_search_engine'][$this->param['list_search_engine_index']] ; 
		} else {
			return ; 
		}
		
		foreach ($this->list_engines as $engine) {
			if ($next_engine==$engine) {
				$this->set_param($engine.'_error_msg', "") ;  
				// check if an error occurs less than 30 min ago on this search engine
				if (time()>$this->get_param($engine.'_error_time')+30*60) { 
					$result = call_user_func(array($this, "_search_".$engine."_api"), $this->param['searched_sentence']) ; 
					if (isset($result['critical'])) {
						$this->set_param($engine.'_error_time', time()) ; 
						$this->set_param($engine.'_error_msg', $result['critical']) ; 
						$this->param['critical'] = $result['critical'] ; 
						return ; 
					} else if (isset($result['error'])) {
						$this->set_param($engine.'error_msg', $result['error']) ; 
						$this->param['error'] = $result['error'] ; 
						return ; 
					} else {
						$this->param['url_buffer'] = $this->_add_result_search($result, $this->param['url_buffer'] ) ; 
						$this->param['at_least_one_engine'] = true ; 
						return ; 
					}
				} else {
					$this->param['error'] = $this->get_param($engine.'_error_msg')." (".sprintf(__("Wait for %s seconds", $this->pluginID), (30*60-(time()-$this->get_param($engine.'_error_time')))."").")" ; 
					return ;
				}
			}
		}
	}

	/** =====================================================================================================
	* step 2 of the process : Search a random sentence of the selected text on Web Engine
	*
	* @return string
	*/
	
	function search_web_engines_specific(){
		
		// Bufferize the list of configured search engine	
		$this->param_specific['list_search_engine_index']++ ; 
		
		// No search engine have been configured
		if (!isset($this->param_specific['list_search_engine'])) {
			$this->param_specific['error'] = __("No search engine has been configured. Please configure at least one in the configuration tab.", $this->pluginID) ; 
			return ; 
		}
		
		// All search engine have been used
		if (count($this->param_specific['list_search_engine'])<=$this->param_specific['list_search_engine_index']-1) {
			// if there is an issue with the search engine we wait ...
			if ($this->param_specific['at_least_one_engine']==false) {
				unset ($this->param_specific['list_search_engine']) ; 
				return ; 
			} else {
				// on recommence avec la prochaine phrase
				if ($this->param_specific['num_sentence']<count(explode(".", $this->param_specific['text']))) {
					$this->param_specific['next_step'] = 'get_text_to_search' ; 
					return ; 
				} elseif (count($this->param_specific['url_buffer'])==0) {
					$this->param_specific['next_step'] = 'stop' ; 
					$this->param_specific['warning'] = __("Search engines have not returned any acceptable result.", $this->pluginID) ; 
					return ; 
				} else {
					$this->pop_buffer_specific() ; 
					$this->param_specific['next_step'] = 'retrieve_content_of_url' ; 
					return ; 
				}
			}
		}
		
		// continue to search
		if (isset($this->param_specific['list_search_engine'][$this->param_specific['list_search_engine_index']])) {
			$next_engine = $this->param_specific['list_search_engine'][$this->param_specific['list_search_engine_index']] ; 
		} else {
			return ; 
		}
		
		foreach ($this->list_engines as $engine) {
			if ($next_engine==$engine) {
				$this->set_param($engine.'_error_msg', "") ;  
				// check if an error occurs less than 30 min ago on this search engine
				if (time()>$this->get_param($engine.'_error_time')+30*60) { 
					// On exclut les phrases de moins de 20 caracteres.
					if (mb_strlen($this->param_specific['searched_sentence'])>20) {
						$result = call_user_func(array($this, "_search_".$engine."_api"), $this->param_specific['searched_sentence']) ; 
					} else {
						$result = array() ; 
					}
					if (isset($result['critical'])) {
						$this->set_param($engine.'_error_time', time()) ; 
						$this->set_param($engine.'_error_msg', $result['critical']) ; 
						$this->param_specific['critical'] = $result['critical'] ; 
						return ; 
					} else if (isset($result['error'])) {
						$this->set_param($engine.'error_msg', $result['error']) ; 
						$this->param_specific['error'] = $result['error'] ; 
						return ; 
					} else {
						$this->param_specific['url_buffer'] = $this->_add_result_search_specific($result, $this->param_specific['url_buffer'] ) ; 
						$this->param_specific['at_least_one_engine'] = true ; 
						return ; 
					}
				} else {
					$this->param_specific['error'] = $this->get_param($engine.'_error_msg')." (".sprintf(__("Wait for %s seconds", $this->pluginID), (30*60-(time()-$this->get_param($engine.'_error_time')))."").")" ; 
					return ;
				}
			}
		}
	}	
	
	/** =====================================================================================================
	* to merge results of a single web engine and the previous results
	*
	* @return string
	*/

	function _add_result_search($new_results, $old_results){
		global $wpdb ; 
	
		$final_results = $old_results ; 
		$filter = explode("\n", $this->get_param('exclude')) ; 
		
		if (is_array($new_results)) {
			foreach ($new_results as $r) {
				$excluded = false ; 
				
				// we check if it is in the buffer
				if (!$excluded) {
					foreach ($final_results as $fr) {
						if (trim($fr[0])==trim($r['url'])) {
							$excluded=true ;
							break ; 
						}
					}
				}

				// We check if the url have been excluded from the exclude list
				if (!$excluded) {
					foreach ($filter as $f) {
						if (strpos($r['url'], $f)!==FALSE) {
							$excluded = true ; 
							break;
						}
					}
				}
				
				// We check if the extract contains enough matching words
				if (!$excluded) {
					$list_words_result = array_unique(explode(" ", strtolower($r['extract']))) ; 
					$list_words_searched = array_flip(array_unique(explode(" ", strtolower($this->param['searched_sentence'])))) ; 
					$count_word = 0 ; 
					$seuil_word = min(count($list_words_searched), count($list_words_result)) ; 
					foreach ($list_words_result as $wr) {
						if (isset($list_words_searched[$wr])) {
							$count_word ++ ; 
						}
					}
					
					if ($count_word<$this->get_param('proximity_words_results')/100*$seuil_word) {
						$excluded = true ; 
						// On incremente quand meme
						$count = $this->get_param('nb_searches') ; 
						$count++ ; 
						$this->set_param('nb_searches', $count) ; 
						
						// On met ˆ jour l'history et on garde que les 100 derniers
						$history = $this->get_param('history_searches') ;
						if (isset($history[date('Ymd')])) {
							$history[date('Ymd')]['search_enough_word'] ++ ; 
						} else {
							$history[date('Ymd')] = array('search_enough_word'=>1, 'search_not_enough_word'=>0, 'compare_text'=>0 ) ; 
						}
						krsort($history, SORT_STRING) ; 
						$history = array_slice($history, 0, 100, true) ; 
						$this->set_param('history_searches', $history) ; 
					} else {
						// On met ˆ jour l'history et on garde que les 100 derniers
						$history = $this->get_param('history_searches') ;
						if (isset($history[date('Ymd')])) {
							$history[date('Ymd')]['search_not_enough_word'] ++ ; 
						} else {
							$history[date('Ymd')] = array('search_enough_word'=>0, 'search_not_enough_word'=>1, 'compare_text'=>0 ) ; 
						}
						krsort($history, SORT_STRING) ; 
						$history = array_slice($history, 0, 100, true) ; 
						$this->set_param('history_searches', $history) ; 
					}
				}
				
				// We check if the url is already stored in the SQL
				if (!$excluded) {
					$nb = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE url='".$r['url']."' AND id_post='".$this->param['id']."'") ; 
					if ($nb!=0) {
						$excluded = true ; 
					}
				}

				// We store if ok
				if (!$excluded) {
					$final_results[] = array($r['url'], $this->param['id'], $r['engine'], (ceil($count_word/$seuil_word*1000)/10)."%") ; 
				}
			}
		}	
		return $final_results ; 
	}
	
	/** =====================================================================================================
	* to merge results of a single web engine and the previous results (specific)
	*
	* @return string
	*/

	function _add_result_search_specific($new_results, $old_results){
		global $wpdb ; 
	
		$final_results = $old_results ; 
		$filter = explode("\n", $this->get_param('exclude')) ; 
		
		if (is_array($new_results)) {
			foreach ($new_results as $r) {
				$excluded = false ; 
				
				// we check if it is in the buffer
				if (!$excluded) {
					foreach ($final_results as $fr) {
						if (trim($fr[0])==trim($r['url'])) {
							$excluded=true ;
							break ; 
						}
					}
				}

				// We check if the url have been excluded from the exclude list
				if (!$excluded) {
					foreach ($filter as $f) {
						if (strpos($r['url'], $f)!==FALSE) {
							$excluded = true ; 
							break;
						}
					}
				}
				
				// We check if the extract contains enough matching words
				if (!$excluded) {
					$list_words_result = array_unique(explode(" ", strtolower($r['extract']))) ; 
					$list_words_searched = array_flip(array_unique(explode(" ", strtolower($this->param_specific['searched_sentence'])))) ; 
					$count_word = 0 ; 
					$seuil_word = min(count($list_words_searched), count($list_words_result)) ; 
					foreach ($list_words_result as $wr) {
						if (isset($list_words_searched[$wr])) {
							$count_word ++ ; 
						}
					}
					
					if ($count_word<$this->get_param('proximity_words_results')/100*$seuil_word) {
						$excluded = true ; 
						// On incremente quand meme
						$count = $this->get_param('nb_searches') ; 
						$count++ ; 
						$this->set_param('nb_searches', $count) ; 
						
						// On met ˆ jour l'history et on garde que les 100 derniers
						$history = $this->get_param('history_searches') ;
						if (isset($history[date('Ymd')])) {
							$history[date('Ymd')]['search_enough_word'] ++ ; 
						} else {
							$history[date('Ymd')] = array('search_enough_word'=>1, 'search_not_enough_word'=>0, 'compare_text'=>0 ) ; 
						}
						krsort($history, SORT_STRING) ; 
						$history = array_slice($history, 0, 100, true) ; 
						$this->set_param('history_searches', $history) ; 
					} else {
						// On met ˆ jour l'history et on garde que les 100 derniers
						$history = $this->get_param('history_searches') ;
						if (isset($history[date('Ymd')])) {
							$history[date('Ymd')]['search_not_enough_word'] ++ ; 
						} else {
							$history[date('Ymd')] = array('search_enough_word'=>0, 'search_not_enough_word'=>1, 'compare_text'=>0 ) ; 
						}
						krsort($history, SORT_STRING) ; 
						$history = array_slice($history, 0, 100, true) ; 
						$this->set_param('history_searches', $history) ; 
					}
				}
				
				// We check if the url is already stored in the SQL
				if (!$excluded) {
					$nb = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE url='".$r['url']."' AND id_post='-1'") ; 
					if ($nb!=0) {
						$excluded = true ; 
					}
				}

				// We store if ok
				if (!$excluded) {
					$final_results[] = array($r['url'], -1, $r['engine'], (ceil($count_word/$seuil_word*1000)/10)."%") ; 
				}
			}
		}	
		return $final_results ; 
	}
	
	/** =====================================================================================================
	* Search a sentence on Google and returns the results
	*
	* @return
	*/
	
	function _search_google_api($query) {
		
		$key = "" ; 
		if ($this->get_param('google_key')!="") {
			$key = "&key=".$this->get_param('google_key') ; 
		}
		
		$url = "http://ajax.googleapis.com/ajax/services/search/web?v=1.0&rsz=large&start=0".$key."&q=".urlencode($query);
		
		$result = wp_remote_get($url) ; 
		if( is_wp_error( $result ) ) {
			return array("error"=>__("Unexpected error when accessing the Google API", $this->pluginID)) ; 
		} else if ($result['response']['code']==200) {
			$body = json_decode($result['body']) ; 
			// Handle Google error
			if ($body->responseStatus==403) {
				if (strpos($body->responseDetails, "Quota Exceeded")!==FALSE) {
					return array("critical"=>__("Your search quota has been exceeded. Please wait until Google authorize a new request", $this->pluginID)) ; 				
				} else {
					return array("critical"=>sprintf(__("Google has blocked your search request: %s", $this->pluginID), $body->responseDetails)) ; 
				}
			}
			$result = array() ; 
			
			foreach ($body->responseData->results as $r) {
				$result[] = array('url'=>$r->unescapedUrl,'extract'=>strip_tags($r->content), 'engine'=>'google') ; 
			}
			return $result ; 
		} else {
			return array("error"=>__("Unexpected error when accessing the Google API (not 200)", $this->pluginID)) ; 
		}

	}	
	
	/** =====================================================================================================
	* Search a sentence on Bing and returns the results
	*
	* @return
	*/
	
	function _search_bing_api($query) {
		if (trim($this->get_param('bing_key'))!="") {
			$args = array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode(trim($this->get_param('bing_key')) . ":" . trim($this->get_param('bing_key')))
				)
			);
		} else {
			return array("error"=>__("Cannot perform search on Bing without Account key", $this->pluginID)) ; 
		}

		$request =  'https://api.datamarket.azure.com/Bing/Search/Web?$format=json&Query='.urlencode('\'' . str_replace("'", " ", $query). '\'');
		
		$result = wp_remote_get($request, $args);
		
		if( is_wp_error( $result ) ) {
			return array("error"=>__("Unexpected error when accessing the Bing API", $this->pluginID)) ; 
		} else if ($result['response']['code']==200) {
			$body = json_decode($result['body']) ; 
			
			$result = array() ; 
			$max_nb = 8 ; 
			foreach ($body->d->results as $r) {
				$result[] = array('url'=>$r->Url, 'extract'=>strip_tags($r->Description), 'engine'=>'bing') ; 
				$max_nb -- ; 
				if ($max_nb<=0) {
					break ; 
				}
			}
			
			return $result ; 
		} else {
			return array("error"=>__("Unexpected error when accessing the Bing API (not 200)", $this->pluginID)) ; 
		}
	}	

		
	/** =====================================================================================================
	* Pop an entry in the buffer
	*
	* @return string
	*/
	
	function pop_buffer(){
		$buffer = $this->param['url_buffer'] ; 
		if (count($buffer)==0) {
			return false; 
		}
		
		$url = array_pop($buffer) ; 
		$count = $this->get_param('nb_searches') ; 
		$count++ ; 
		$this->set_param('nb_searches', $count) ; 
		
		// On met ˆ jour l'history et on garde que les 100 derniers
		$history = $this->get_param('history_searches') ;
		if (isset($history[date('Ymd')])) {
			$history[date('Ymd')]['compare_text'] ++ ; 
		} else {
			$history[date('Ymd')] = array('search_enough_word'=>0, 'search_not_enough_word'=>0, 'compare_text'=>1 ) ; 
		}
		krsort($history, SORT_STRING) ; 
		$history = array_slice($history, 0, 100, true) ; 
		$this->set_param('history_searches', $history) ; 

				
		$this->param['url_buffer'] = $buffer ; 
		$this->param['url'] = $url[0] ; 
		$this->param['id'] = $url[1] ; 
		return true ; 
	}

	/** =====================================================================================================
	* Pop an entry in the buffer
	*
	* @return string
	*/
	
	function pop_buffer_specific(){
		$buffer = $this->param_specific['url_buffer'] ; 
		if (count($buffer)==0) {
			return false; 
		}
		
		$url = array_pop($buffer) ; 
		$count = $this->get_param('nb_searches') ; 
		$count++ ; 
		$this->set_param('nb_searches', $count) ; 
		
		// On met ˆ jour l'history et on garde que les 100 derniers
		$history = $this->get_param('history_searches') ;
		if (isset($history[date('Ymd')])) {
			$history[date('Ymd')]['compare_text'] ++ ; 
		} else {
			$history[date('Ymd')] = array('search_enough_word'=>0, 'search_not_enough_word'=>0, 'compare_text'=>1 ) ; 
		}
		krsort($history, SORT_STRING) ; 
		$history = array_slice($history, 0, 100, true) ; 
		$this->set_param('history_searches', $history) ; 

				
		$this->param_specific['url_buffer'] = $buffer ; 
		$this->param_specific['url'] = $url[0] ; 
		$this->param_specific['id'] = $url[1] ; 
		return true ; 
	}
	
	/** =====================================================================================================
	* step 2-bis of the process : Get the content of the next item in the URL buffer
	*
	* @return string
	*/
	
	function retrieve_content_of_url(){
		
		$max_size = 500 ;  // Max 500ko
		$nb_max_test = 10 ; 
		
		$nb_it = 0 ; 
		do {
			$head = wp_remote_head($this->param['url']) ;
			$nb_it++ ;
		} while ( (is_wp_error( $head )) && ($nb_it < $nb_max_test) ) ; 
		
		
		if (!is_wp_error( $head ) ) {
		
			if ((isset($head['headers']['content-length']))&&($head['headers']['content-length']<$max_size*1024)) {
				
				$nb_it = 0 ; 
				do {
					$contentpage = wp_remote_get($this->param['url']) ;
					$nb_it++ ;
				} while ((is_wp_error( $contentpage )) && ($nb_it<$nb_max_test)) ;
								
				if (is_wp_error( $contentpage ) ) {
					$this->param['error']  = sprintf(__("Error retrieving the url with the function %s", $this->pluginID),"<i>'wp_remote_get'</i>") ; 
					$this->param['next_step'] = 'stop' ; 
				}  else if ($contentpage['response']['code']==200) {
					$this->param['content']  =  $this->clean_text($contentpage['body']) ; 
					if (mb_strlen($this->param['content'])==0) {
						$this->param['warning']  = sprintf(__("The retrieved content have no character (%s).", $this->pluginID), mb_strlen($contentpage['body'])) ; 
						$this->param['next_step'] = 'stop' ; 					
					} else {
						$this->param['information']  = sprintf(__("Success retrieving the website (by using %s): %s characters have been retrieved", $this->pluginID),"wp_remote_get", mb_strlen($contentpage['body'])." (".mb_strlen($this->param['content']).")") ; 
						$this->param['next_step'] = 'compare_text' ; 
					}
				} else {
					ob_start() ; 
						print_r($contentpage) ; 
					$content_error = ob_get_clean() ; 
					$this->param['warning']  = sprintf(__("Error retrieving the url. The retrieval returns: %s)", $this->pluginID), "<i>".htmlentities($content_error)."</i>") ; 
					$this->param['next_step'] = 'stop' ; 
				}
				
			} else {
				// We try to use another method
				if (function_exists('file_get_contents')) {
					// Create a stream
					$opts = array(
						'http'=>array(
							'method'=>"GET",
							'header'=>"Cookie: foo=bar\r\n"
						)
					);
					$context = stream_context_create($opts);
					
					$nb_it = 0 ; 
					do {
						$content = @file_get_contents($this->param['url'], false, $context , -1, $max_size*1024) ; 
						$nb_it++ ;
					} while (($content==false) && ($nb_it<$nb_max_test)) ; 

					if ($content==false) {
						$this->param['error']  = sprintf(__("Error retrieving the url (%s)", $this->pluginID),"file_get_contents") ; 
						$this->param['next_step'] = 'stop' ; 
					} else {
						$this->param['content'] = $this->clean_text($content);
						$this->param['information']  = sprintf(__("Success retrieving the website (by using %s): %s characters have been retrieved", $this->pluginID),"file_get_contents", mb_strlen($content)." (".mb_strlen($this->param['content']).")") ; 
						$this->param['next_step'] = 'compare_text' ; 
					}
				} else {
					$this->param['warning']  = __("Size of the data is too big to be compared", $this->pluginID) ; 
					$this->param['next_step'] = 'stop' ; 
				}			
			}
		} else {
			$this->param['error']  = sprintf(__("The function %s does not seems to works.", $this->pluginID),"<i>'wp_remote_head'</i>") ; 
			$this->param['next_step'] = 'stop' ; 
		}
		
	}
	
	/** =====================================================================================================
	* step 2-bis of the process : Get the content of the next item in the URL buffer
	*
	* @return string
	*/
	
	function retrieve_content_of_url_specific(){
		
		$max_size = 500 ;  // Max 500ko
		$nb_max_test = 10 ; 
		
		$nb_it = 0 ; 
		do {
			$head = wp_remote_head($this->param_specific['url']) ;
			$nb_it++ ;
		} while ( (is_wp_error( $head )) && ($nb_it < $nb_max_test) ) ; 
		
		
		if (!is_wp_error( $head ) ) {
		
			if ((isset($head['headers']['content-length']))&&($head['headers']['content-length']<$max_size*1024)) {
				
				$nb_it = 0 ; 
				do {
					$contentpage = wp_remote_get($this->param_specific['url']) ;
					$nb_it++ ;
				} while ((is_wp_error( $contentpage )) && ($nb_it<$nb_max_test)) ;
								
				if (is_wp_error( $contentpage ) ) {
					$this->param_specific['error']  = sprintf(__("Error retrieving the url with the function %s", $this->pluginID),"<i>'wp_remote_get'</i>") ; 
					$this->param_specific['next_step'] = 'stop' ; 
				}  else if ($contentpage['response']['code']==200) {
					$this->param_specific['content']  =  $this->clean_text($contentpage['body']) ; 
					if (mb_strlen($this->param_specific['content'])==0) {
						$this->param_specific['warning']  = sprintf(__("The retrieved content have no character (%s).", $this->pluginID), mb_strlen($contentpage['body'])) ; 
						$this->param_specific['next_step'] = 'stop' ; 					
					} else {
						$this->param_specific['information']  = sprintf(__("Success retrieving the website (by using %s): %s characters have been retrieved", $this->pluginID),"wp_remote_get", mb_strlen($contentpage['body'])." (".mb_strlen($this->param_specific['content']).")") ; 
						$this->param_specific['next_step'] = 'compare_text' ; 
					}
				} else {
					ob_start() ; 
						print_r($contentpage) ; 
					$content_error = ob_get_clean() ; 
					$this->param_specific['warning']  = sprintf(__("Error retrieving the url. The retrieval returns: %s)", $this->pluginID), "<i>".htmlentities($content_error)."</i>") ; 
					$this->param_specific['next_step'] = 'stop' ; 
				}
				
			} else {
				// We try to use another method
				if (function_exists('file_get_contents')) {
					// Create a stream
					$opts = array(
						'http'=>array(
							'method'=>"GET",
							'header'=>"Cookie: foo=bar\r\n"
						)
					);
					$context = stream_context_create($opts);
					
					$nb_it = 0 ; 
					do {
						$content = @file_get_contents($this->param_specific['url'], false, $context , -1, $max_size*1024) ; 
						$nb_it++ ;
					} while (($content==false) && ($nb_it<$nb_max_test)) ; 

					if ($content==false) {
						$this->param_specific['error']  = sprintf(__("Error retrieving the url (%s)", $this->pluginID),"file_get_contents") ; 
						$this->param_specific['next_step'] = 'stop' ; 
					} else {
						$this->param_specific['content'] = $this->clean_text($content);
						$this->param_specific['information']  = sprintf(__("Success retrieving the website (by using %s): %s characters have been retrieved", $this->pluginID),"file_get_contents", mb_strlen($content)." (".mb_strlen($this->param_specific['content']).")") ; 
						$this->param_specific['next_step'] = 'compare_text' ; 
					}
				} else {
					$this->param_specific['warning']  = __("Size of the data is too big to be compared", $this->pluginID) ; 
					$this->param_specific['next_step'] = 'stop' ; 
				}			
			}
		} else {
			$this->param_specific['error']  = sprintf(__("The function %s does not seems to works.", $this->pluginID),"<i>'wp_remote_head'</i>") ; 
			$this->param_specific['next_step'] = 'stop' ; 
		}
	}
	
	/** =====================================================================================================
	* step 3 of the process : Compare text
	*
	* @return string
	*/
	
	function compare_text(){
	
		// We determine where we stopped
		
		if (!isset($this->param['results_proximity'])) {
			$cur_text = 0 ; 	// The sentence cursor in the post
			$cur_result = 0 ; 	// The sentence cursor in the result
		} else {
			$cur_text = count($this->param['results_proximity'])-1 ; 
			$cur_result = count($this->param['results_proximity'][$cur_text])-1 ; 
		}
				
		// Start the counter
		$max_calc = $this->get_param('nb_iteration_max') ; 
		$nb_calc = 0 ; 
		
		$len_text = mb_strlen($this->param['text']) ; 
		$delta_text = ceil(max(1,($len_text-$this->get_param('nb_char_prox')))/$this->get_param('img_height')) ; 

		$len_result = mb_strlen($this->param['content']) ; 
		$delta_result = ceil(max(1,($len_result-$this->get_param('nb_char_prox')))/$this->get_param('img_width')) ; 

		// Compute the number of iteration to solve the pb
		$nb_total_iterations = ceil(max(1,($len_text-$this->get_param('nb_char_prox')))/$delta_text) * ceil(max(1,($len_result-$this->get_param('nb_char_prox')))/$delta_result) ; 
		
		$this->param['nb_total_iterations'] = $nb_total_iterations ; 
		
		$anticipated_break = false ; 	
		for ($i=$cur_text ; $i*$delta_text < max(1,($len_text-$this->get_param('nb_char_prox'))) ; $i++, $cur_result=0) {
			for ($h=$cur_result ; $h*$delta_result < max(1,($len_result-$this->get_param('nb_char_prox'))) ; $h++) {
				
				$proximity = $this->get_param('nb_char_prox') ; 
				
				$nb_occ_text = max(1,min(10,$delta_text/$this->get_param('nb_char_minidelta'))) ; 
				$mini_delta_text = ceil($delta_text/$nb_occ_text) ; 

				$nb_occ_result = max(1,min(10,$delta_result/$this->get_param('nb_char_minidelta'))) ; 
				$mini_delta_result = ceil($delta_result/$nb_occ_result) ; 
				
				
				for ($ii=0 ; $ii < $nb_occ_text ; $ii++) {
					for ($hh=0 ; $hh < $nb_occ_result ; $hh++) {
						
						$str1 = mb_substr($this->param['text'],$i*$delta_text+$ii*$mini_delta_text,$this->get_param('nb_char_prox')) ; 
						$str2 = mb_substr($this->param['content'],$h*$delta_result+$hh*$mini_delta_result,$this->get_param('nb_char_prox')) ; 
						
						$proximity = min($proximity, levenshtein($str1, $str2)) ; 
						
						$nb_calc ++ ; 
						
						// To avoid saturation
						if ($nb_calc>=$max_calc) {
							$anticipated_break = true ; 
							break ; 
						}
					}
					// To avoid saturation
					if ($nb_calc>=$max_calc) {
						$anticipated_break = true ; 
						break ; 
					}
				}
				
				$this->param['results_proximity'][$i][$h] = $proximity ; 
				
				// i = y et h = x
				
				// si le score du pixel depasse 0.4*$this->get_param('nb_char_prox')
				
				//     on regarde dans le carrŽ (i-2; h-2) (i-1; h) pour voir s'il y a au moins un pixel noir
				//         On calcule la distance minimal entre ce pixel et les pixels dans le carrŽ
				//         On ajoute ˆ la valeur max(-20,(20-distance minimal)) mais sans depasser $this->get_param('nb_char_prox')
				//            i.e. on ajoute max 20 si la distance minimal est faible 
				//                 on retire max 20 si la distance minimal est grande
				
				$max_add = 20 ; 
				
				if  ($this->get_param('enable_proximity_score_v2'))  {
				
					$this->param['results_proximity_v2'][$i][$h] = $this->param['results_proximity'][$i][$h] ; 
					
					if ($proximity>0.4*$this->get_param('nb_char_prox')) {
					
						$distance_minimal = 9999 ; 
						for ($wi=1 ; $wi<3 ; $wi++) {
							for ($wh=0 ; $wh<3 ; $wh++) {
								if (isset($this->param['results_proximity'][$i-$wi][$h-$wh])) {
									$distance_minimal = min($distance_minimal, abs($proximity - $this->param['results_proximity'][$i-$wi][$h-$wh])) ; 
								}
							}	
						}
					
						$this->param['results_proximity_v2'][$i][$h] = max(0,min($this->get_param('nb_char_prox'), $proximity + max(-$max_add,($max_add-$distance_minimal)))) ; 

				//     on regarde dans le carrŽ (i+2; h-2) (i+1; h) pour voir s'il n'y a pas de pixel noir (mais on exlu si ou meme h)
				//         On calcule la distance minimal entre ce pixel et les pixels dans le carrŽ
				//         On ajoute ˆ la valeur min(20,(distance minimal-20)) mais sans depasser $this->get_param('nb_char_prox')
				//            i.e. on ajoute max 20 si la distance minimal est grande 
				//                 on retire max 20 si la distance minimal est faible
					
						$distance_minimal = 9999 ; 
						for ($wi=1 ; $wi<3 ; $wi++) {
							for ($wh=0 ; $wh<3 ; $wh++) {
								if (isset($this->param['results_proximity'][$i+$wi][$h-$wh])) {
									$distance_minimal = min($distance_minimal, abs($proximity - $this->param['results_proximity'][$i+$wi][$h-$wh])) ; 
								}
							}	
						}
					
						$this->param['results_proximity_v2'][$i][$h] = max(0,min($this->get_param('nb_char_prox'), $proximity + min($max_add,($distance_minimal-$max_add)))) ; 

				// sinon, on le met a 0
				
					} else {
							$this->param['results_proximity_v2'][$i][$h] = 30 ; 
					}
				}
					
				$nb_calc ++ ; 
				// To avoid saturation
				if ($nb_calc>=$max_calc) {
					$anticipated_break = true ; 
					break ; 
				}
			}

			// To avoid saturation
			if ($nb_calc>=$max_calc) {
				$anticipated_break = true ; 
				break ; 
			}
		}
		
		$this->param['nb_current_iterations'] = ceil($i*max(1,($len_text-$this->get_param('nb_char_prox')))/$delta_text)+$h ; 
	
		if ($anticipated_break) {
			// nothing 
		} else {
			$this->param["next_step"] = "filter_results" ;	
			if  ($this->get_param('enable_proximity_score_v2'))  {
				// On remplace avec l'image filtrŽ
				$this->param['results_proximity'] = $this->param['results_proximity_v2'] ;
				unset($this->param['results_proximity_v2']) ; 
			}
		}
	}
	
	/** =====================================================================================================
	* step 3 of the process : Compare text
	*
	* @return string
	*/
	
	function compare_text_specific(){
	
		// We determine where we stopped
		
		if (!isset($this->param_specific['results_proximity'])) {
			$cur_text = 0 ; 	// The sentence cursor in the post
			$cur_result = 0 ; 	// The sentence cursor in the result
		} else {
			$cur_text = count($this->param_specific['results_proximity'])-1 ; 
			$cur_result = count($this->param_specific['results_proximity'][$cur_text])-1 ; 
		}
				
		// Start the counter
		$max_calc = $this->get_param('nb_iteration_max') ; 
		$nb_calc = 0 ; 
		
		$len_text = mb_strlen($this->param_specific['text']) ; 
		$delta_text = ceil(max(1,($len_text-$this->get_param('nb_char_prox')))/$this->get_param('img_height')) ; 

		$len_result = mb_strlen($this->param_specific['content']) ; 
		$delta_result = ceil(max(1,($len_result-$this->get_param('nb_char_prox')))/$this->get_param('img_width')) ; 

		// Compute the number of iteration to solve the pb
		$nb_total_iterations = ceil(max(1,($len_text-$this->get_param('nb_char_prox')))/$delta_text) * ceil(max(1,($len_result-$this->get_param('nb_char_prox')))/$delta_result) ; 
		
		$this->param_specific['nb_total_iterations'] = $nb_total_iterations ; 
		
		$anticipated_break = false ; 	
		for ($i=$cur_text ; $i*$delta_text < max(1,($len_text-$this->get_param('nb_char_prox'))) ; $i++, $cur_result=0) {
			for ($h=$cur_result ; $h*$delta_result < max(1,($len_result-$this->get_param('nb_char_prox'))) ; $h++) {
				
				$proximity = $this->get_param('nb_char_prox') ; 
				
				$nb_occ_text = max(1,min(10,$delta_text/$this->get_param('nb_char_minidelta'))) ; 
				$mini_delta_text = ceil($delta_text/$nb_occ_text) ; 

				$nb_occ_result = max(1,min(10,$delta_result/$this->get_param('nb_char_minidelta'))) ; 
				$mini_delta_result = ceil($delta_result/$nb_occ_result) ; 
				
				
				for ($ii=0 ; $ii < $nb_occ_text ; $ii++) {
					for ($hh=0 ; $hh < $nb_occ_result ; $hh++) {
						
						$str1 = mb_substr($this->param_specific['text'],$i*$delta_text+$ii*$mini_delta_text,$this->get_param('nb_char_prox')) ; 
						$str2 = mb_substr($this->param_specific['content'],$h*$delta_result+$hh*$mini_delta_result,$this->get_param('nb_char_prox')) ; 
						
						$proximity = min($proximity, levenshtein($str1, $str2)) ; 
						
						$nb_calc ++ ; 
						
						// To avoid saturation
						if ($nb_calc>=$max_calc) {
							$anticipated_break = true ; 
							break ; 
						}
					}
					// To avoid saturation
					if ($nb_calc>=$max_calc) {
						$anticipated_break = true ; 
						break ; 
					}
				}
				
				$this->param_specific['results_proximity'][$i][$h] = $proximity ; 
				
				// i = y et h = x
				
				// si le score du pixel depasse 0.4*$this->get_param('nb_char_prox')
				
				//     on regarde dans le carrŽ (i-2; h-2) (i-1; h) pour voir s'il y a au moins un pixel noir
				//         On calcule la distance minimal entre ce pixel et les pixels dans le carrŽ
				//         On ajoute ˆ la valeur max(-20,(20-distance minimal)) mais sans depasser $this->get_param('nb_char_prox')
				//            i.e. on ajoute max 20 si la distance minimal est faible 
				//                 on retire max 20 si la distance minimal est grande
				
				$max_add = 20 ; 
				
				if  ($this->get_param('enable_proximity_score_v2'))  {
				
					$this->param_specific['results_proximity_v2'][$i][$h] = $this->param_specific['results_proximity'][$i][$h] ; 
					
					if ($proximity>0.4*$this->get_param('nb_char_prox')) {
					
						$distance_minimal = 9999 ; 
						for ($wi=1 ; $wi<3 ; $wi++) {
							for ($wh=0 ; $wh<3 ; $wh++) {
								if (isset($this->param_specific['results_proximity'][$i-$wi][$h-$wh])) {
									$distance_minimal = min($distance_minimal, abs($proximity - $this->param_specific['results_proximity'][$i-$wi][$h-$wh])) ; 
								}
							}	
						}
					
						$this->param_specific['results_proximity_v2'][$i][$h] = max(0,min($this->get_param('nb_char_prox'), $proximity + max(-$max_add,($max_add-$distance_minimal)))) ; 

				//     on regarde dans le carrŽ (i+2; h-2) (i+1; h) pour voir s'il n'y a pas de pixel noir (mais on exlu si ou meme h)
				//         On calcule la distance minimal entre ce pixel et les pixels dans le carrŽ
				//         On ajoute ˆ la valeur min(20,(distance minimal-20)) mais sans depasser $this->get_param('nb_char_prox')
				//            i.e. on ajoute max 20 si la distance minimal est grande 
				//                 on retire max 20 si la distance minimal est faible
					
						$distance_minimal = 9999 ; 
						for ($wi=1 ; $wi<3 ; $wi++) {
							for ($wh=0 ; $wh<3 ; $wh++) {
								if (isset($this->param_specific['results_proximity'][$i+$wi][$h-$wh])) {
									$distance_minimal = min($distance_minimal, abs($proximity - $this->param_specific['results_proximity'][$i+$wi][$h-$wh])) ; 
								}
							}	
						}
					
						$this->param_specific['results_proximity_v2'][$i][$h] = max(0,min($this->get_param('nb_char_prox'), $proximity + min($max_add,($distance_minimal-$max_add)))) ; 

				// sinon, on le met a 0
				
					} else {
							$this->param_specific['results_proximity_v2'][$i][$h] = 30 ; 
					}
				}
					
				$nb_calc ++ ; 
				// To avoid saturation
				if ($nb_calc>=$max_calc) {
					$anticipated_break = true ; 
					break ; 
				}
			}

			// To avoid saturation
			if ($nb_calc>=$max_calc) {
				$anticipated_break = true ; 
				break ; 
			}
		}
		
		$this->param_specific['nb_current_iterations'] = ceil($i*max(1,($len_text-$this->get_param('nb_char_prox')))/$delta_text)+$h ; 
	
		if ($anticipated_break) {
			// nothing 
		} else {
			$this->param_specific["next_step"] = "filter_results" ;	
			if  ($this->get_param('enable_proximity_score_v2'))  {
				// On remplace avec l'image filtrŽ
				$this->param_specific['results_proximity'] = $this->param_specific['results_proximity_v2'] ;
				unset($this->param_specific['results_proximity_v2']) ; 
			}
		}
	}	
	
	/** =====================================================================================================
	* step 4 of the process : Filter results
	*
	* @return string
	*/
	
	function filter_results(){
		
		$size1 = count($this->param['results_proximity']) ; 
		$size2 = count($this->param['results_proximity'][0]) ; 
		
		if (isset($this->param['nb_plagiary'])) {
			$plagiary = $this->param['nb_plagiary'] ; 
		} else {
			$plagiary = 0 ; 
			$this->param["filter_index"] = 0 ; 
		}
		
		$nb_iter=0 ; 
		$anticipated_break = false ; 
		$start = $this->param["filter_index"]  ; 
		
		for ($i=$start ; $i<$size1 ; $i++) {
			$is_plagiary = false ;  
			for ($j=0 ; $j<$size2 ; $j++) {
				if ($this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100<=(100-$this->get_param('equal_proximity'))) {
					$is_plagiary = true ; 
					break ;
				}
			}
			if ($is_plagiary) {
				$plagiary ++ ; 
			}
			
			$nb_iter++ ; 
			$this->param["filter_index"] ++ ; 
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}		
		
		if (!$anticipated_break) {
			unset ($this->param["filter_index"]) ; 
			unset($this->param['nb_plagiary']) ; 
			
			if ($plagiary/$size1*100>=$this->get_param('threshold')){
				$this->param['percentage_proximity']= ceil($plagiary/$size1*1000)/10 ; 
				$this->param["next_step"] = "compute_proximity_images" ;
			} else {
				$this->param["warning"] = sprintf(__("The proximity score is only %s and then the website is not considered as a plagiary",$this->pluginID), (ceil($plagiary/$size1*1000)/10)."%");
				$this->param["next_step"] = "stop" ;

				unset($this->param['results_proximity']) ; 
				unset($this->param['image_proximity']) ; 
				unset($this->param['percentage_proximity']) ; 
				unset($this->param['nb_total_iterations']) ; 
				unset($this->param['nb_current_iterations']) ; 
				unset($this->param['content']) ; 
			}
		} else {
			$this->param['nb_plagiary'] = $plagiary ; 
			//nothing
		}
	
	}

	/** =====================================================================================================
	* step 4 of the process : Filter results
	*
	* @return string
	*/
	
	function filter_results_specific(){
		
		$size1 = count($this->param_specific['results_proximity']) ; 
		$size2 = count($this->param_specific['results_proximity'][0]) ; 
		
		if (isset($this->param_specific['nb_plagiary'])) {
			$plagiary = $this->param_specific['nb_plagiary'] ; 
		} else {
			$plagiary = 0 ; 
			$this->param_specific["filter_index"] = 0 ; 
		}
		
		$nb_iter=0 ; 
		$anticipated_break = false ; 
		$start = $this->param_specific["filter_index"]  ; 
		
		for ($i=$start ; $i<$size1 ; $i++) {
			$is_plagiary = false ;  
			for ($j=0 ; $j<$size2 ; $j++) {
				if ($this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100<=(100-$this->get_param('equal_proximity'))) {
					$is_plagiary = true ; 
					break ;
				}
			}
			if ($is_plagiary) {
				$plagiary ++ ; 
			}
			
			$nb_iter++ ; 
			$this->param_specific["filter_index"] ++ ; 
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}		
		
		if (!$anticipated_break) {
			unset ($this->param_specific["filter_index"]) ; 
			unset($this->param_specific['nb_plagiary']) ; 
			
			if ($plagiary/$size1*100>=$this->get_param('threshold')){
				$this->param_specific['percentage_proximity']= ceil($plagiary/$size1*1000)/10 ; 
				$this->param_specific["next_step"] = "compute_proximity_images" ;
			} else {
				$this->param_specific["warning"] = sprintf(__("The proximity score is only %s and then the website is not considered as a plagiary",$this->pluginID), (ceil($plagiary/$size1*1000)/10)."%");
				$this->param_specific["next_step"] = "stop" ;

				unset($this->param_specific['results_proximity']) ; 
				unset($this->param_specific['image_proximity']) ; 
				unset($this->param_specific['percentage_proximity']) ; 
				unset($this->param_specific['nb_total_iterations']) ; 
				unset($this->param_specific['nb_current_iterations']) ; 
				unset($this->param_specific['content']) ; 
			}
		} else {
			$this->param_specific['nb_plagiary'] = $plagiary ; 
			//nothing
		}
	}

	/** =====================================================================================================
	* step 5 of the process : Compute proximity Images
	*
	* @return string
	*/
	
	function compute_proximity_images(){
		global $blog_id ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
				
		$max_width = $this->get_param('img_width') ; 
		$max_height = $this->get_param('img_height') ; 
		
		$extreme_color1 = array(255, 255, 255) ; 
		$extreme_color2 = array(0,0,0) ; 
				
		if (!isset($this->param["image_proximity"])) {
			$rand = sha1(rand(0, 2000000).$this->param['url']) ; 
			$this->param["image_proximity"] = "/sedlex/plagiary-search/".$blog_fold."proximity".$rand.".png" ;
			// Create an image
			$im = imagecreatetruecolor($max_width+1, $max_height+1);
			// sets background to white
			$background = imagecolorallocate($im, 0, 0, 0);
			$this->param["image_index"] = 0 ; 
		} else {
			$im = @imagecreatefrompng(WP_CONTENT_DIR.$this->param["image_proximity"]);
		}
		
		$nb_iter = 0 ; 
		$anticipated_break = false ; 
		$start = $this->param["image_index"] ; 
		
		for ($i= $start; $i<count($this->param['results_proximity']) ; $i++) {
			for ($j=0 ; $j<count($this->param['results_proximity'][$i]) ; $j++) {
				$x1 = floor($i*$max_width/count($this->param['results_proximity']))+1 ; 
				$x2 = floor(($i+1)*$max_width/count($this->param['results_proximity']))+1 ; 
				$y1 = floor($j*$max_height/count($this->param['results_proximity'][$i]))+1 ; 
				$y2 = floor(($j+1)*$max_height/count($this->param['results_proximity'][$i]))+1 ; 
				
				$red = floor(abs($extreme_color1[0]-$extreme_color2[0])*($this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[0],$extreme_color2[0])) ; 
				$green = floor(abs($extreme_color1[1]-$extreme_color2[1])*($this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[1],$extreme_color2[1])) ; 
				$blue = floor(abs($extreme_color1[2]-$extreme_color2[2])*($this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[2],$extreme_color2[2])) ; 
				
				$color = imagecolorallocate($im, $red, $green, $blue);
				imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);
				unset($color) ; 
			}
			
			$nb_iter++ ; 
			$this->param["image_index"] ++ ; 
			
			// We delete the current line
			//$this->param['results_proximity'][$i] = array() ; 
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}
		
		
		$redcolor = imagecolorallocate($im, 255, 0, 0);
		imagefilledrectangle($im, 0, 0, 1, 1, $redcolor);
				
		imagepng($im, WP_CONTENT_DIR.$this->param["image_proximity"]);
		imagedestroy($im);
		
		if (!$anticipated_break) {
			unset($this->param["image_index"]) ; 
			$this->param["next_step"] = "text_format" ;
		} else {
			// we do nothing
		}
	}

	/** =====================================================================================================
	* step 5 of the process : Compute proximity Images
	*
	* @return string
	*/
	
	function compute_proximity_images_specific(){
		global $blog_id ; 
		// We create the folder for the backup files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}
				
		$max_width = $this->get_param('img_width') ; 
		$max_height = $this->get_param('img_height') ; 
		
		$extreme_color1 = array(255, 255, 255) ; 
		$extreme_color2 = array(0,0,0) ; 
				
		if (!isset($this->param_specific["image_proximity"])) {
			$rand = sha1(rand(0, 2000000).$this->param_specific['url']) ; 
			$this->param_specific["image_proximity"] = "/sedlex/plagiary-search/".$blog_fold."proximity".$rand.".png" ;
			// Create an image
			$im = imagecreatetruecolor($max_width+1, $max_height+1);
			// sets background to white
			$background = imagecolorallocate($im, 0, 0, 0);
			$this->param_specific["image_index"] = 0 ; 
		} else {
			$im = @imagecreatefrompng(WP_CONTENT_DIR.$this->param_specific["image_proximity"]);
		}
		
		$nb_iter = 0 ; 
		$anticipated_break = false ; 
		$start = $this->param_specific["image_index"] ; 
		
		for ($i= $start; $i<count($this->param_specific['results_proximity']) ; $i++) {
			for ($j=0 ; $j<count($this->param_specific['results_proximity'][$i]) ; $j++) {
				$x1 = floor($i*$max_width/count($this->param_specific['results_proximity']))+1 ; 
				$x2 = floor(($i+1)*$max_width/count($this->param_specific['results_proximity']))+1 ; 
				$y1 = floor($j*$max_height/count($this->param_specific['results_proximity'][$i]))+1 ; 
				$y2 = floor(($j+1)*$max_height/count($this->param_specific['results_proximity'][$i]))+1 ; 
				
				$red = floor(abs($extreme_color1[0]-$extreme_color2[0])*($this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[0],$extreme_color2[0])) ; 
				$green = floor(abs($extreme_color1[1]-$extreme_color2[1])*($this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[1],$extreme_color2[1])) ; 
				$blue = floor(abs($extreme_color1[2]-$extreme_color2[2])*($this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox'))+min($extreme_color1[2],$extreme_color2[2])) ; 
				
				$color = imagecolorallocate($im, $red, $green, $blue);
				imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);
				unset($color) ; 
			}
			
			$nb_iter++ ; 
			$this->param_specific["image_index"] ++ ; 
			
			// We delete the current line
			//$this->param_specific['results_proximity'][$i] = array() ; 
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}
		
		
		$redcolor = imagecolorallocate($im, 255, 0, 0);
		imagefilledrectangle($im, 0, 0, 1, 1, $redcolor);
				
		imagepng($im, WP_CONTENT_DIR.$this->param_specific["image_proximity"]);
		imagedestroy($im);
		
		if (!$anticipated_break) {
			unset($this->param_specific["image_index"]) ; 
			$this->param_specific["next_step"] = "text_format" ;
		} else {
			// we do nothing
		}
	}
	
	/** =====================================================================================================
	* step 5-bis of the process : Mise en page du texte
	*
	* @return string
	*/
	
	function text_format(){
		
		$size1 = count($this->param['results_proximity']) ; 
		$size2 = count($this->param['results_proximity'][0]) ; 
		$delta1 = ceil(max(1,(mb_strlen($this->param['text'])-$this->get_param('nb_char_prox')))/$this->get_param('img_height')) ;  
		$delta2 = ceil(max(1,(mb_strlen($this->param['content'])-$this->get_param('nb_char_prox')))/$this->get_param('img_width')) ; 
		$nb_iter=0 ; 
		$anticipated_break = false ; 
		if (isset($this->param["format_index1"])) {
			$start1 = $this->param["format_index1"]  ; 
		} else {
			$start1 = 0 ; 
			$this->param["format_index1"] = 0 ; 
			$this->param['new_text'] = "" ; 
		}
		if (isset($this->param["format_index2"])) {
			$start2 = $this->param["format_index2"]  ;
		} else {
			$start2 = 0 ; 
			$this->param["format_index2"] = 0 ; 
			$this->param['new_content'] = "" ; 
		} 
		
		// We look in the first text		
		for ($i=$start1 ; $i<$size1 ; $i++) {
			$max = 100 ;  
			for ($j=0 ; $j<$size2 ; $j++) {
				$max = min($max, $this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100) ; 
			}			
			$nb_iter++ ; 
			$this->param["format_index1"] ++ ; 
			
			// We construct the new string
			$hexval = dechex(ceil(min(255, $max*255/100))) ;
			if (mb_strlen($hexval)==1) {
				$hexval = "0".$hexval ; 
			} 
			$this->param['new_text'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param['text'], $i*$delta1, $delta1)."</span>" ; 
			
			// if the end we keep the end
			if ($i==$size1-1) {
				$this->param['new_text'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param['text'], ($i+1)*$delta1)."</span>" ; 
			}
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}		
		
		// We look in the second text		
		if (!$anticipated_break) {
			for ($j=$start2 ; $j<$size2 ; $j++) {	
				$max = 100 ;  
				for ($i=0 ; $i<$size1 ; $i++) {
					$max = min($max, $this->param['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100) ; 
				}			
				$nb_iter++ ; 
				$this->param["format_index2"] ++ ; 
				
				// We construct the new string
				$hexval = dechex(ceil(min(255, $max*255/100))) ;
				if (mb_strlen($hexval)==1) {
					$hexval = "0".$hexval ; 
				} 
				$this->param['new_content'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param['content'], $j*$delta2, $delta2)."</span>" ; 
				
				// if the end we keep the end
				if ($j==$size2-1) {
					$this->param['new_content'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param['content'], ($j+1)*$delta2)."</span>" ; 
				}
				
				// To avoid saturation
				if ($nb_iter>=$this->get_param('max_line_per_iter')) {
					$anticipated_break = true ; 
					break ; 
				}
			}		
		}
		
		if (!$anticipated_break) {
			unset ($this->param["format_index1"]) ; 			
			unset ($this->param["format_index2"]) ; 			
			unset($this->param["results_proximity"]) ; 
			unset($this->param['content']) ; 
			$this->param["next_step"] = "store_result" ;
		} else {
			//nothing
		}
	}

	/** =====================================================================================================
	* step 5-bis of the process : Mise en page du texte
	*
	* @return string
	*/
	
	function text_format_specific(){
		
		$size1 = count($this->param_specific['results_proximity']) ; 
		$size2 = count($this->param_specific['results_proximity'][0]) ; 
		$delta1 = ceil(max(1,(mb_strlen($this->param_specific['text'])-$this->get_param('nb_char_prox')))/$this->get_param('img_height')) ;  
		$delta2 = ceil(max(1,(mb_strlen($this->param_specific['content'])-$this->get_param('nb_char_prox')))/$this->get_param('img_width')) ; 
		$nb_iter=0 ; 
		$anticipated_break = false ; 
		if (isset($this->param_specific["format_index1"])) {
			$start1 = $this->param_specific["format_index1"]  ; 
		} else {
			$start1 = 0 ; 
			$this->param_specific["format_index1"] = 0 ; 
			$this->param_specific['new_text'] = "" ; 
		}
		if (isset($this->param_specific["format_index2"])) {
			$start2 = $this->param_specific["format_index2"]  ;
		} else {
			$start2 = 0 ; 
			$this->param_specific["format_index2"] = 0 ; 
			$this->param_specific['new_content'] = "" ; 
		} 
		
		// We look in the first text		
		for ($i=$start1 ; $i<$size1 ; $i++) {
			$max = 100 ;  
			for ($j=0 ; $j<$size2 ; $j++) {
				$max = min($max, $this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100) ; 
			}			
			$nb_iter++ ; 
			$this->param_specific["format_index1"] ++ ; 
			
			// We construct the new string
			$hexval = dechex(ceil(min(255, $max*255/100))) ;
			if (mb_strlen($hexval)==1) {
				$hexval = "0".$hexval ; 
			} 
			$this->param_specific['new_text'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param_specific['text'], $i*$delta1, $delta1)."</span>" ; 
			
			// if the end we keep the end
			if ($i==$size1-1) {
				$this->param_specific['new_text'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param_specific['text'], ($i+1)*$delta1)."</span>" ; 
			}
			
			// To avoid saturation
			if ($nb_iter>=$this->get_param('max_line_per_iter')) {
				$anticipated_break = true ; 
				break ; 
			}
		}		
		
		// We look in the second text		
		if (!$anticipated_break) {
			for ($j=$start2 ; $j<$size2 ; $j++) {	
				$max = 100 ;  
				for ($i=0 ; $i<$size1 ; $i++) {
					$max = min($max, $this->param_specific['results_proximity'][$i][$j]/$this->get_param('nb_char_prox')*100) ; 
				}			
				$nb_iter++ ; 
				$this->param_specific["format_index2"] ++ ; 
				
				// We construct the new string
				$hexval = dechex(ceil(min(255, $max*255/100))) ;
				if (mb_strlen($hexval)==1) {
					$hexval = "0".$hexval ; 
				} 
				$this->param_specific['new_content'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param_specific['content'], $j*$delta2, $delta2)."</span>" ; 
				
				// if the end we keep the end
				if ($j==$size2-1) {
					$this->param_specific['new_content'] .= "<span style='color:#".$hexval.$hexval.$hexval."'>".mb_substr($this->param_specific['content'], ($j+1)*$delta2)."</span>" ; 
				}
				
				// To avoid saturation
				if ($nb_iter>=$this->get_param('max_line_per_iter')) {
					$anticipated_break = true ; 
					break ; 
				}
			}		
		}
		
		if (!$anticipated_break) {
			unset ($this->param_specific["format_index1"]) ; 			
			unset ($this->param_specific["format_index2"]) ; 			
			unset($this->param_specific["results_proximity"]) ; 
			unset($this->param_specific['content']) ; 
			$this->param_specific["next_step"] = "store_result" ;
		} else {
			//nothing
		}
	}	
	
	
	/** =====================================================================================================
	* step 6 of the process : Store the results
	*
	* @return string
	*/
	
	function store_result(){
		global $wpdb, $blog_id ; 
		
		// We create the folder for the img files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		$insert = "INSERT INTO ".$this->table_name." (id_post, url, proximity, image, text1, text2, ignored, date_maj) VALUES ('".$this->param["id"]."', '".esc_attr($this->param["url"])."', '".$this->param['percentage_proximity']."', '".$this->param['image_proximity']."', '".esc_attr($this->param['new_text'])."', '".esc_attr($this->param['new_content'])."', FALSE, NOW())" ; 
		$wpdb->query($insert) ; 
		
		// generate the screenshot
		// ScreenShot with wkhtmltoimage
		if ($this->get_param('enable_wkhtmltoimage')) {
			$img_thum = $this->wkurltoimage($this->param["url"], $this->get_param('enable_wkhtmltoimage_winw'), 150, 170) ; 
			
		}

		// and send the email
		if ($this->get_param('send_email_when_found')) {
			if (preg_match("/([\w\-]+\@[\w\-]+\.[\w\-]+)/",$this->get_param('send_email_when_found_email'))) {
				$message = __("Dear Sirs,", $this->pluginID)."\n" ; 
				$message .= sprintf(__("A new plagiary has been found on %s", $this->pluginID), get_bloginfo('name')." (".site_url().")")."\n" ; 
				$message .= sprintf(__("    * The plagiary page is %s", $this->pluginID), $this->param["url"])."\n" ; 
				$message .= sprintf(__("    * Your page is %s", $this->pluginID), get_permalink($this->param["id"]))."\n\n" ; 
				$message .= sprintf(__("Visit %s to see the details.", $this->pluginID), get_admin_url())."\n" ; 
				$message .= __("Best regards,", $this->pluginID)."\n" ; 
				if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($this->param["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) {
					wp_mail($this->get_param('send_email_when_found_email'), "[".get_bloginfo('name')."] ".__('Found a new plagiary', $this->pluginID), $message,"", array(WP_CONTENT_DIR.$this->param["image_proximity"], WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($this->param["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) ; 
				} else {
					if ($this->get_param('enable_wkhtmltoimage')) {
						$message .= "\n".sprintf(__("PS: the image %s cannot be attached.", $this->pluginID), $blog_fold."wk_".sha1($this->param["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")."\n" ; 
					}
					wp_mail($this->get_param('send_email_when_found_email'), "[".get_bloginfo('name')."] ".__('Found a new plagiary', $this->pluginID), $message,"", array(WP_CONTENT_DIR.$this->param["image_proximity"])) ; 
				}
			}
		}
		
		unset($this->param['image_proximity']) ; 
		unset($this->param['percentage_proximity']) ; 
		unset($this->param['nb_total_iterations']) ; 
		unset($this->param['nb_current_iterations']) ; 
		unset($this->param['new_text']) ; 
		unset($this->param['new_content']) ; 
		unset($this->param["error"] ) ; 
		
	
		$this->param["next_step"] = "stop" ;
	}
	
	/** =====================================================================================================
	* step 6 of the process : Store the results
	*
	* @return string
	*/
	
	function store_result_specific(){
		global $wpdb, $blog_id ; 
		
		// We create the folder for the img files
		$blog_fold = "" ; 
		if (is_multisite()) {
			$blog_fold = $blog_id."/" ; 
		}

		$insert = "INSERT INTO ".$this->table_name." (id_post, url, proximity, image, text1, text2, ignored, date_maj, specific_text, specific_sha1) VALUES ('-1', '".esc_attr($this->param_specific["url"])."', '".$this->param_specific['percentage_proximity']."', '".$this->param_specific['image_proximity']."', '".esc_attr($this->param_specific['new_text'])."', '".esc_attr($this->param_specific['new_content'])."', FALSE, NOW(), '".esc_attr($this->param_specific['text'])."', '".sha1($this->param_specific['text'])."')" ; 
		$wpdb->query($insert) ; 
		
		// generate the screenshot
		// ScreenShot with wkhtmltoimage
		if ($this->get_param('enable_wkhtmltoimage')) {
			$img_thum = $this->wkurltoimage($this->param_specific["url"], $this->get_param('enable_wkhtmltoimage_winw'), 150, 170) ; 
			
		}

		// and send the email
		if ($this->get_param('send_email_when_found')) {
			if (preg_match("/([\w\-]+\@[\w\-]+\.[\w\-]+)/",$this->get_param('send_email_when_found_email'))) {
				$message = __("Dear Sirs,", $this->pluginID)."\n" ;
				$message .= sprintf(__("A new plagiary has been found on %s", $this->pluginID), get_bloginfo('name')." (".site_url().")")."\n" ; 
				$message .= sprintf(__("    * The plagiary page is %s", $this->pluginID), $this->param_specific["url"])."\n" ; 
				$message .= sprintf(__("    * Your text is %s", $this->pluginID), "<code>".$this->param_specific["text"]."</code>")."\n\n" ; 
				$message .= sprintf(__("Visit %s to see the details.", $this->pluginID), get_admin_url())."\n" ; 
				$message .= __("Best regards,", $this->pluginID)."\n" ; 
				if (is_file(WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($this->param_specific["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) {
					wp_mail($this->get_param('send_email_when_found_email'), "[".get_bloginfo('name')."] ".__('Found a new plagiary', $this->pluginID), $message,"", array(WP_CONTENT_DIR.$this->param_specific["image_proximity"], WP_CONTENT_DIR."/sedlex/plagiary-search/".$blog_fold."wk_".sha1($this->param_specific["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")) ; 
				} else {
					if ($this->get_param('enable_wkhtmltoimage')) {
						$message .= "\n".sprintf(__("PS: the image %s cannot be attached.", $this->pluginID), $blog_fold."wk_".sha1($this->param_specific["url"].$this->get_param('enable_wkhtmltoimage_winw')).".jpg")."\n" ; 
					}
					wp_mail($this->get_param('send_email_when_found_email'), "[".get_bloginfo('name')."] ".__('Found a new plagiary', $this->pluginID), $message,"", array(WP_CONTENT_DIR.$this->param_specific["image_proximity"])) ; 
				}
			}
		}
		
		unset($this->param_specific['image_proximity']) ; 
		unset($this->param_specific['percentage_proximity']) ; 
		unset($this->param_specific['nb_total_iterations']) ; 
		unset($this->param_specific['nb_current_iterations']) ; 
		unset($this->param_specific['new_text']) ; 
		unset($this->param_specific['new_content']) ; 
		unset($this->param_specific["error"] ) ; 
		
	
		$this->param_specific["next_step"] = "stop" ;
	}
	
	/** =====================================================================================================
	* step 7 of the process : stop of the process
	*
	* @return string
	*/
	
	function stop(){
		if ((isset($this->param['url_buffer'] ))&&(count($this->param['url_buffer'])!=0)) {
			$this->pop_buffer() ; 
			$this->param['next_step'] = 'retrieve_content_of_url' ; 
			return ; 
		}  else {
			unset($this->param["url"]) ; 
			unset($this->param["id"]) ; 
			unset($this->param["searched_sentence"]) ; 
			$this->param = array('next_step' => 'get_text_to_search') ; 
			return ; 
		}
	}
	
	/** =====================================================================================================
	* step 7 of the process : stop of the process
	*
	* @return string
	*/
	
	function stop_specific(){
		if ((isset($this->param_specific['url_buffer'] ))&&(count($this->param_specific['url_buffer'])!=0)) {
			$this->pop_buffer_specific() ; 
			$this->param_specific['next_step'] = 'retrieve_content_of_url' ; 
			return ; 
		}  else {
			unset($this->param_specific["url"]) ; 
			unset($this->param_specific["searched_sentence"]) ; 
			$this->param_specific['next_step'] = 'end' ; 
			return ; 
		}
	}
	/** =====================================================================================================
	* Callback to reject plagiary
	*
	* @return string
	*/
	
	function notPlagiary(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (FALSE===$wpdb->query("UPDATE ".$this->table_name." SET ignored = TRUE, authorized = FALSE WHERE id=".$id)) {
			echo "error" ; 
		} else {
			echo "ok" ; 
		}
		die() ; 
	}

	/** =====================================================================================================
	* Callback to say it is plagiary
	*
	* @return string
	*/
	
	function plagiary(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (FALSE===$wpdb->query("UPDATE ".$this->table_name." SET ignored = FALSE, authorized = FALSE WHERE id=".$id)) {
			echo "error" ; 
		} else {
			echo "ok" ; 
		}
		die() ; 
	}
	
	/** =====================================================================================================
	* Callback to indicated not authorized
	*
	* @return string
	*/
	
	function notAuthorized(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (FALSE===$wpdb->query("UPDATE ".$this->table_name." SET ignored = FALSE, authorized = FALSE WHERE id=".$id)) {
			echo "error" ; 
		} else {
			echo "ok" ; 
		}
		die() ; 
	}

	/** =====================================================================================================
	* Callback to say it is authorized copy
	*
	* @return string
	*/
	
	function authorized(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (FALSE===$wpdb->query("UPDATE ".$this->table_name." SET ignored = FALSE, authorized = TRUE WHERE id=".$id)) {
			echo "error" ; 
		} else {
			echo "ok" ; 
		}
		die() ; 
	}
	
	/** =====================================================================================================
	* Callback to say it is deleted copy
	*
	* @return string
	*/
	
	function delete_copy(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (FALSE===$wpdb->query("DELETE FROM ".$this->table_name." WHERE id=".$id)) {
			echo "error" ; 
		} else {
			echo "ok" ; 
		}
		die() ; 
	}
	
	/** =====================================================================================================
	* Callback to view the texts
	*
	* @return string
	*/
	
	function viewText(){
		global $wpdb ;
		$id = $_POST['id'] ; 
		if (!is_numeric($id)) {
			echo "Go away: it is not an integer" ; 
			die() ; 
		} 
		
		$result = $wpdb->get_row("SELECT text1, text2 FROM ".$this->table_name." WHERE id=".$id) ; 
		
		ob_start() ; 
			echo "<h2>".__('The text of your article', $this->pluginID)."</h2>" ;
			echo "<p>".stripslashes($result->text1)."</p>" ;
			echo "<h2>".__('The text of the external website', $this->pluginID)."</h2>" ;
			echo "<p>".stripslashes($result->text2)."</p>" ;

		$pop = new SLFramework_Popup(__('Show the proximity between the text', $this->pluginID), ob_get_clean()) ;
		$pop->render() ;
		die() ; 
	}

}

$plagiary_search = plagiary_search::getInstance();

?>