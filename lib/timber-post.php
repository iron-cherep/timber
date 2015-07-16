<?php

/**
 * This is the object you use to access or extend WordPress posts,
 * Think of it as Timber's (more accessible) version of WP_Post
 * @package  timber
 */
class TimberPost extends TimberCore implements TimberCoreInterface {

	/**
	 * @var string $ImageClass the name of the class to handle images by default
	 */
	public $ImageClass = 'TimberImage';
	/**
	 * @var string $PostClass the name of the class to handle posts by default
	 */
	public $PostClass = 'TimberPost';
	/**
	 * @var string $object_type what does this class represent in WordPress terms?
	 */
	public $object_type = 'post';
	/**
	 * @var string $representation what does this class represent in WordPress terms?
	 */
	public static $representation = 'post';
	/**
	 * @internal
	 * @var string $_content stores the processed content internally
	 */
	protected $_content;
	/**
	 * @internal
	 * @var array $_get_terms stores the results of a get_terms method call
	 */
	protected $_get_terms;
	/**
	 * @var string $_permalink the returned permalink from WP's get_permalink function
	 */
	protected $_permalink;
	/**
	 * @var array $_next stores the results of the next TimberPost in a set inside an array (in order to manage by-taxonomy)
	 */
	protected $_next = array();
	/**
	 * @var array $_prev stores the results of the previous TimberPost in a set inside an array (in order to manage by-taxonomy)
	 */
	protected $_prev = array();

	/**
	 * @var string $class stores the CSS classes for the post (ex: "post post-type-book post-123")
	 */
	public $class;
	/**
	 * @deprecated since 0.21.7
	 * @var string $display_date @deprecated stores the display date (ex: "October 6, 1984"), 
	 */
	public $display_date;
	/**
	 * @var string $id the numeric WordPress id of a post
	 */
	public $id;
	/**
	 * @var string $ID the numeric WordPress id of a post, capitalized to match WP usage
	 */
	public $ID;
	/**
	 * @var int $post_author the numeric ID of the a post's author corresponding to the wp_user dtable
	 */
	public $post_author;
	/**
	 * @var string $post_content the raw text of a WP post as stored in the database
	 */
	public $post_content;
	/**
	 * @var string $post_date the raw date string as stored in the WP database, ex: 2014-07-05 18:01:39
	 */
	public $post_date;
	/**
	 * @var string $post_exceprt the raw text of a manual post exceprt as stored in the database
	 */
	public $post_excerpt;

	/**
	* @var int $post_parent the numeric ID of a post's parent post
	*/
	public $post_parent;
	/**
	 * @var string $post_title the raw text of a post's title as stored in the database
	 */
	public $post_title;
	/**
	 * @var string $post_type the name of the post type, this is the machine name (so "my_custom_post_type" as opposed to "My Custom Post Type")
	 */
	public $post_type;

	/**
	 * @var string $slug the URL-safe slug, this corresponds to the poorly-named "post_name" in the WP database, ex: "hello-world"
	 */
	public $slug;

	/**
	 * If you send the constructor nothing it will try to figure out the current post id based on being inside The_Loop
	 * @param mixed $pid
	 */
	function __construct($pid = null) {
		$pid = $this->determine_id( $pid );
		$this->init($pid);
	}

	/**
	 * tries to figure out what post you want to get if not explictly defined (or if it is, allows it to be passed through)
	 * @param mixed a value to test against
	 * @return int the numberic id we should be using for this post object
	 */
	protected function determine_id($pid) {
		global $wp_query;
		if ($pid === null &&
			isset($wp_query->queried_object_id)
			&& $wp_query->queried_object_id
			&& isset($wp_query->queried_object)
			&& is_object($wp_query->queried_object)
			&& get_class($wp_query->queried_object) == 'WP_Post'
			) {
			$pid = $wp_query->queried_object_id;
		} else if ($pid === null && $wp_query->is_home && isset($wp_query->queried_object_id) && $wp_query->queried_object_id )  {
			//hack for static page as home page
			$pid = $wp_query->queried_object_id;
		} else if ($pid === null) {
			$gtid = false;
			$maybe_post = get_post();
			if (isset($maybe_post->ID)){
				$gtid = true;
			}
			if ( $gtid ) {
				$pid = get_the_ID();
			}
			if ( !$pid ) {
				global $wp_query;
				if ( isset($wp_query->query['p']) ) {
					$pid = $wp_query->query['p'];
				}
			}
		}
		if ($pid === null && ($pid_from_loop = TimberPostGetter::loop_to_id())) {
			$pid = $pid_from_loop;
		}
		return $pid;
	}

	/**
	 * Outputs the title of the post if you do something like `<h1>{{post}}</h1>`
	 * @return string
	 */
	function __toString() {
		return $this->title();
	}


	/**
	 * Initializes a TimberPost
	 * @param int|bool $pid
	 */
	function init($pid = false) {
		if ($pid === false) {
			$pid = get_the_ID();
		}
		if (is_numeric($pid)) {
			$this->ID = $pid;
		}
		$post_info = $this->get_info($pid);
		$this->import($post_info);
		/* deprecated, adding for support for older themes */
		$this->display_date = $this->date();
		//cant have a function, so gots to do it this way
		$post_class = $this->post_class();
		$this->class = $post_class;
	}

	/**
	 * Get the URL that will edit the current post/object
	 * @return bool|string
	 */
	function get_edit_url() {
		if ($this->can_edit()) {
			return get_edit_post_link($this->ID);
		}
	}

	/**
	 * updates the post_meta of the current object with the given value
	 * @param string $field
	 * @param mixed $value
	 */
	public function update($field, $value) {
		if (isset($this->ID)) {
			update_post_meta($this->ID, $field, $value);
			$this->$field = $value;
		}
	}


	/**
	 * @internal
	 * takes a mix of integer (post ID), string (post slug), or object to return a WordPress post object from WP's built-in get_post() function
	 * @param mixed $pid
	 * @return WP_Post on success
	 */
	protected function prepare_post_info($pid = 0) {
		if (is_string($pid) || is_numeric($pid) || (is_object($pid) && !isset($pid->post_title)) || $pid === 0) {
			$pid = self::check_post_id($pid);
			$post = get_post($pid);
			if ($post) {
				return $post;
			} else {
				$post = get_page($pid);
				return $post;
			}
		}
		//we can skip if already is WP_Post
		return $pid;
	}


	/**
	 * @internal
	 * helps you find the post id regardless of whether you send a string or whatever
	 * @param integer $pid ;
	 * @return integer ID number of a post
	 */
	protected function check_post_id($pid) {
		if (is_numeric($pid) && $pid === 0) {
			$pid = get_the_ID();
			return $pid;
		}
		if (!is_numeric($pid) && is_string($pid)) {
			$pid = self::get_post_id_by_name($pid);
			return $pid;
		}
		if (!$pid) {
			return null;
		}
		return $pid;
	}


	/**
	 * @internal
	 * get_post_id_by_name($post_name)
	 * @param string $post_name
	 * @return int
	 */
	static function get_post_id_by_name($post_name) {
		global $wpdb;
		$query = $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s LIMIT 1", $post_name);
		$result = $wpdb->get_row($query);
		if (!$result) {
			return null;
		}
		return $result->ID;
	}

	/**
	 * get a preview of your post, if you have an excerpt it will use that,
	 * otherwise it will pull from the post_content.
	 * @example If there's a <!-- more --> tag it will use that to mark where to pull through.
	 * ```twig
	 * <p>{{post.get_preview(50)}}</p>
	 * ```
	 * @param int $len
	 * @param bool $force
	 * @param string $readmore
	 * @param bool $strip
	 * @return string
	 */
	function get_preview($len = 50, $force = false, $readmore = 'Read More', $strip = true) {
		$text = '';
		$trimmed = false;
		if (isset($this->post_excerpt) && strlen($this->post_excerpt)) {
			if ($force) {
				$text = TimberHelper::trim_words($this->post_excerpt, $len, false);
				$trimmed = true;
			} else {
				$text = $this->post_excerpt;
			}
		}
		if (!strlen($text) && strpos($this->post_content, '<!--more-->') !== false) {
			$pieces = explode('<!--more-->', $this->post_content);
			$text = $pieces[0];
			if ($force) {
				$text = TimberHelper::trim_words($text, $len, false);
				$trimmed = true;
			}
		}
		if (!strlen($text)) {
			$text = TimberHelper::trim_words($this->get_content(), $len, false);
			$trimmed = true;
		}
		if (!strlen(trim($text))) {
			return trim($text);
		}
		if ($strip) {
			$text = trim(strip_tags($text));
		}
		if (strlen($text)) {
			$text = trim($text);
			$last = $text[strlen($text) - 1];
			if ($last != '.' && $trimmed) {
				$text .= ' &hellip; ';
			}
			if (!$strip) {
				$last_p_tag = strrpos($text, '</p>');
				if ($last_p_tag !== false) {
					$text = substr($text, 0, $last_p_tag);
				}
				if ($last != '.' && $trimmed) {
					$text .= ' &hellip; ';
				}
			}

			if ($readmore) {
				$text .= ' <a href="' . $this->get_permalink() . '" class="read-more">' . $readmore . '</a>';
			}

			if (!$strip) {
				$text .= '</p>';
			}
		}
		return trim($text);
	}

	/**
	 * @internal
	 * gets the post custom and attaches it to the current object
	 * @param bool|int $pid a post ID number
	 */
	function import_custom($pid = false) {
		if (!$pid) {
			$pid = $this->ID;
		}
		$customs = $this->get_post_custom($pid);
		$this->import($customs);
	}

	/**
	 * @internal
	 * Used internally to fetch the metadata fields (wp_postmeta table)
	 * and attach them to our TimberPost object
	 * @param int $pid
	 * @return array
	 */
	protected function get_post_custom($pid) {
		apply_filters('timber_post_get_meta_pre', array(), $pid, $this);
		$customs = get_post_custom($pid);
		if (!is_array($customs) || empty($customs)) {
			return array();
		}
		foreach ($customs as $key => $value) {
			if (is_array($value) && count($value) == 1 && isset($value[0])) {
				$value = $value[0];
			}
			$customs[$key] = maybe_unserialize($value);
		}
		$customs = apply_filters('timber_post_get_meta', $customs, $pid, $this);
		return $customs;
	}

	/**
	 * @internal
	 * @see TimberPost::thumbnail
	 * @return null|TimberImage
	 */
	function get_thumbnail() {
		if (function_exists('get_post_thumbnail_id')) {
			$tid = get_post_thumbnail_id($this->ID);
			if ($tid) {
				return new $this->ImageClass($tid);
			}
		}
	}

	/**
	 * @internal
	 * @see TimberPost::link
	 * @return string
	 */
	function get_permalink() {
		if (isset($this->_permalink)) {
			return $this->_permalink;
		}
		$this->_permalink = get_permalink($this->ID);
		return $this->_permalink;
	}

	/**
	 * get the permalink for a post object
	 * In your templates you should use link:
	 * <a href="{{post.link}}">Read my post</a>
	 * @return string
	 */
	function get_link() {
		return $this->get_permalink();
	}

	/**
	 * Get the next post in WordPress's ordering
	 * @param bool $taxonomy
	 * @return TimberPost|boolean
	 */
	function get_next($taxonomy = false) {
		if (!isset($this->_next) || !isset($this->_next[$taxonomy])) {
			global $post;
			$this->_next = array();
			$old_global = $post;
			$post = $this;
			if ($taxonomy) {
				$adjacent = get_adjacent_post(true, '', false, $taxonomy);
			} else {
				$adjacent = get_adjacent_post(false, '', false);
			}

			if ($adjacent) {
				$this->_next[$taxonomy] = new $this->PostClass($adjacent);
			} else {
				$this->_next[$taxonomy] = false;
			}
			$post = $old_global;
		}
		return $this->_next[$taxonomy];
	}

	/**
	 * Get a data array of pagination so you can navigate to the previous/next for a paginated post
	 * @return array
	 */
	public function get_pagination() {
		global $post, $page, $numpages, $multipage;
		$post = $this;
		$ret = array();
		if ($multipage) {
			for ($i = 1; $i <= $numpages; $i++) {
				$link = self::get_wp_link_page($i);
				$data = array('name' => $i, 'title' => $i, 'text' => $i, 'link' => $link);
				if ($i == $page) {
					$data['current'] = true;
				}
				$ret['pages'][] = $data;
			}
			$i = $page - 1;
			if ($i) {
				$link = self::get_wp_link_page($i);
				$ret['prev'] = array('link' => $link);
			}
			$i = $page + 1;
			if ($i <= $numpages) {
				$link = self::get_wp_link_page($i);
				$ret['next'] = array('link' => $link);
			}
		}
		return $ret;
	}

	/**
	 * @param int $i
	 * @return string
	 */
	protected static function get_wp_link_page($i) {
		$link = _wp_link_page($i);
		$link = new SimpleXMLElement($link . '</a>');
		if (isset($link['href'])) {
			return $link['href'];
		}
		return '';
	}

	/**
	 * Get the permalink for a post, but as a relative path
	 * For example, where {{post.link}} would return "http://example.org/2015/07/04/my-cool-post"
	 * this will return the relative version: "/2015/07/04/my-cool-post"
	 * @return string
	 */
	function get_path() {
		return TimberURLHelper::get_rel_url($this->get_link());
	}

	/**
	 * Get the next post in WordPress's ordering
	 * @param bool $taxonomy
	 * @return TimberPost|boolean
	 */
	function get_prev($taxonomy = false) {
		if (isset($this->_prev) && isset($this->_prev[$taxonomy])) {
			return $this->_prev[$taxonomy];
		}
		global $post;
		$old_global = $post;
		$post = $this;
		$within_taxonomy = ($taxonomy) ? $taxonomy : 'category';
		$adjacent = get_adjacent_post(($taxonomy), '', true, $within_taxonomy);

		$prev_in_taxonomy = false;
		if ($adjacent) {
			$prev_in_taxonomy = new $this->PostClass($adjacent);
		}
		$this->_prev[$taxonomy] = $prev_in_taxonomy;
		$post = $old_global;
		return $this->_prev[$taxonomy];
	}

	/**
	 * Get the parent post of the post
	 * @return bool|TimberPost
	 */
	function get_parent() {
		if (!$this->post_parent) {
			return false;
		}
		return new $this->PostClass($this->post_parent);
	}

	/**
	 * Gets a User object from the author of the post
	 * @example <p class="byline">{{post.get_author.name}}</p>
	 * @return bool|TimberUser
	 */
	function get_author() {
		if (isset($this->post_author)) {
			return new TimberUser($this->post_author);
		}
	}

	/**
	 * Get the author (WordPress user) who last modified the post
	 * @return bool|TimberUser
	 */
	function get_modified_author() {
		$user_id = get_post_meta($this->ID, '_edit_last', true);
		return ($user_id ? new TimberUser($user_id) : $this->get_author());
	}

	/**
	 * Used internally by init, etc. to build TimberPost object
	 * @param  int $pid
	 * @return null|object|WP_Post
	 */
	protected function get_info($pid) {
		$post = $this->prepare_post_info($pid);
		if (!isset($post->post_status)) {
			return null;
		}
		$post->status = $post->post_status;
		$post->id = $post->ID;
		$post->slug = $post->post_name;
		$customs = $this->get_post_custom($post->ID);
		$post->custom = $customs;
		$post = (object)array_merge((array)$customs, (array)$post);
		return $post;
	}

	/**
	 * This is deprecated!
	 * But it would get the human-friendly date that should actually display in a .twig template
	 * @deprecated
	 * @param string $use
	 * @return string
	 */
	function get_display_date($use = 'post_date') {
		return date(get_option('date_format'), strtotime($this->$use));
	}

	/**
	 * @param  string $date_format
	 * @return string
	 */
	function get_date($date_format = '') {
		$df = $date_format ? $date_format : get_option('date_format');
		$the_date = (string)mysql2date($df, $this->post_date);
		return apply_filters('get_the_date', $the_date, $date_format);
	}

	/**
	 * @param  string $date_format
	 * @return string
	 */
	function get_modified_date($date_format = '') {
		$df = $date_format ? $date_format : get_option('date_format');
		$the_time = $this->get_modified_time($df, null, $this->ID, true);
		return apply_filters('get_the_modified_date', $the_time, $date_format);
	}

	/**
	 * @param  string $time_format
	 * @return string
	 */
	function get_modified_time($time_format = '') {
		$tf = $time_format ? $time_format : get_option('time_format');
		$the_time = get_post_modified_time($tf, false, $this->ID, true);
		return apply_filters('get_the_modified_time', $the_time, $time_format);
	}

	/**
	 * @param string $post_type
	 * @param bool $childPostClass
	 * @return array
	 */
	function get_children($post_type = 'any', $childPostClass = false) {
		if ($childPostClass === false) {
			$childPostClass = $this->PostClass;
		}
		if ($post_type == 'parent') {
			$post_type = $this->post_type;
		}
		$children = get_children('post_parent=' . $this->ID . '&post_type=' . $post_type . '&numberposts=-1&orderby=menu_order title&order=ASC');
		foreach ($children as &$child) {
			$child = new $childPostClass($child->ID);
		}
		$children = array_values($children);
		return $children;
	}

	/**
	 * Get the comments for a post
	 * @see TimberPost::comments
	 * @param int $ct
	 * @param string $order
	 * @param string $type
	 * @param string $status
	 * @param string $CommentClass
	 * @return array|mixed
	 */
	function get_comments($ct = 0, $order = 'wp', $type = 'comment', $status = 'approve', $CommentClass = 'TimberComment') {
		$args = array('post_id' => $this->ID, 'status' => $status, 'order' => $order);
		if ($ct > 0) {
			$args['number'] = $ct;
		}
		if ($order == 'wp') {
			$args['order'] = get_option('comment_order');
		}

        $comments = get_comments($args);
        $tComments = array();

        foreach($comments as $key => &$comment) {
            $tComment = new $CommentClass($comment);
            $tComments[$tComment->id] = $tComment;
        }

        foreach($tComments as $key => $comment) {
            if ($comment->is_child()) {
                unset($tComments[$comment->id]);

                if (isset($tComments[$comment->comment_parent])) {
                    $tComments[$comment->comment_parent]->children[] = $comment;
                }
            }
        }
        $tComments = array_values($tComments);

        return $tComments;
    }

	/**
	 * Get the categories for a post
	 * @example "docs/examples/get_categories.twig"
	 * @return array of TimberTerms
	 */
	function get_categories() {
		return $this->get_terms('category');
	}

	/**
	 * @return mixed
	 */
	function get_category() {
		$cats = $this->get_categories();
		if (count($cats) && isset($cats[0])) {
			return $cats[0];
		}
	}

	/** # get terms is good
	 *
	 */

	/**
	 * @param string $tax
	 * @param bool $merge
	 * @param string $TermClass
	 * @return array
	 */
	function get_terms($tax = '', $merge = true, $TermClass = 'TimberTerm') {
		if (is_string($merge) && class_exists($merge)){
			$TermClass = $merge;
		}
		if (is_string($tax)) {
			if (isset($this->_get_terms) && isset($this->_get_terms[$tax])) {
				return $this->_get_terms[$tax];
			}
		}
		if (!strlen($tax) || $tax == 'all' || $tax == 'any') {
			$taxs = get_object_taxonomies($this->post_type);
		} else if (is_array($tax)) {
			$taxs = $tax;
		} else {
			$taxs = array($tax);
		}
		$ret = array();
		foreach ($taxs as $tax) {
			if ($tax == 'tags' || $tax == 'tag') {
				$tax = 'post_tag';
			} else if ($tax == 'categories') {
				$tax = 'category';
			}
			$terms = wp_get_post_terms($this->ID, $tax);
			if (!is_array($terms) && is_object($terms) && get_class($terms) == 'WP_Error') {
				//something is very wrong
				TimberHelper::error_log('You have an error retrieving terms on a post in timber-post.php:628');
				TimberHelper::error_log('tax = ' . $tax);
				TimberHelper::error_log($terms);
			} else {
				foreach ($terms as &$term) {
					$term = new $TermClass($term->term_id, $tax);
				}
				if ($merge && is_array($terms)) {
					$ret = array_merge($ret, $terms);
				} else if (count($terms)) {
					$ret[$tax] = $terms;
				}
			}
		}
		if (!isset($this->_get_terms)) {
			$this->_get_terms = array();
		}
		$this->_get_terms[$tax] = $ret;
		return $ret;
	}

	/**
	 * @param string|int $term_name_or_id
	 * @param string $taxonomy
	 * @return bool
	 */
	function has_term($term_name_or_id, $taxonomy = 'all') {
		if ($taxonomy == 'all' || $taxonomy == 'any') {
			$taxes = get_object_taxonomies($this->post_type, 'names');
			$ret = false;
			foreach ($taxes as $tax) {
				if (has_term($term_name_or_id, $tax, $this->ID)) {
					$ret = true;
					break;
				}
			}
			return $ret;
		}
		return has_term($term_name_or_id, $taxonomy, $this->ID);
	}

	/**
	 * @param string $field
	 * @return TimberImage
	 */
	function get_image($field) {
		return new $this->ImageClass($this->$field);
	}

	/**
	 * Gets an array of tags for you to use
	 * ```twig
	 * <ul class="tags">
	 *     {% for tag in post.tags %}
	 *         <li>{{tag.name}}</li>
	 *     {% endfor %}
	 * </ul>
	 * ```
	 * @return array
	 */
	function get_tags() {
		return $this->get_terms('tags');
	}

	/**
	 * Outputs the title with filters applied
	 * ```twig
	 * <h1>{{post.get_title}}</h1>
	 * ```
	 * @return string
	 */
	function get_title() {
		return apply_filters('the_title', $this->post_title, $this->ID);
	}

	/**
	 *  ## Displays the content of the post with filters, shortcodes and wpautop applied
	 *  <div class="article-text">{{post.get_content}}</div>
	 */

	/**
	 * @param int $len
	 * @param int $page
	 * @return string
	 */
	function get_content($len = 0, $page = 0) {
		if ($len == 0 && $page == 0 && $this->_content) {
			return $this->_content;
		}
		$content = $this->post_content;
		if ($len) {
			$content = wp_trim_words($content, $len);
		}
		if ($page) {
			$contents = explode('<!--nextpage-->', $content);
			$page--;
			if (count($contents) > $page) {
				$content = $contents[$page];
			}
		}
		$content = apply_filters('the_content', ($content));
		if ($len == 0 && $page == 0) {
			$this->_content = $content;
		}
		return $content;
	}

	/**
	 * @return string
	 */
	function get_paged_content() {
		global $page;
		return $this->get_content(0, $page);
	}
	/**
	 * ```twig
	 * This post is from <span>{{ post.get_post_type.labels.plural }}</span>
	 * ```
	 * @return mixed
	 */
	public function get_post_type() {
		return get_post_type_object($this->post_type);
	}

	/**
	 * @return int
	 */
	public function get_comment_count() {
		if (isset($this->ID)) {
			return get_comments_number($this->ID);
		} else {
			return 0;
		}
	}

	/**
	 * @param string $field_name
	 * @return mixed
	 */
	public function get_field($field_name) {
		$value = apply_filters('timber_post_get_meta_field_pre', null, $this->ID, $field_name, $this);
		if ($value === null) {
			$value = get_post_meta($this->ID, $field_name);
			if (is_array($value) && count($value) == 1) {
				$value = $value[0];
			}
			if (is_array($value) && count($value) == 0) {
				$value = null;
			}
		}
		$value = apply_filters('timber_post_get_meta_field', $value, $this->ID, $field_name, $this);
		return $value;
	}

	/**
	 * @param string $field_name
	 */
	function import_field($field_name) {
		$this->$field_name = $this->get_field($field_name);
	}

	/**
	 * @return mixed
	 */
	function get_format() {
		return get_post_format($this->ID);
	}

	/**
	 * @param string $class
	 * @return string
	 */
	public function post_class($class='') {
		global $post;
		$old_global_post = $post;
		$post = $this;
		$class_array = get_post_class($class, $this->ID);
		$post = $old_global_post;
		if (is_array($class_array)){
			return implode(' ', $class_array);
		}
		return $class_array;
	}

	// Docs

	/**
	 * @return array
	 * @codeCoverageIgnore
	 */
	public function get_method_values() {
		$ret = parent::get_method_values();
		$ret['author'] = $this->author();
		$ret['categories'] = $this->categories();
		$ret['category'] = $this->category();
		$ret['children'] = $this->children();
		$ret['comments'] = $this->comments();
		$ret['content'] = $this->content();
		$ret['edit_link'] = $this->edit_link();
		$ret['format'] = $this->format();
		$ret['link'] = $this->link();
		$ret['next'] = $this->next();
		$ret['pagination'] = $this->pagination();
		$ret['parent'] = $this->parent();
		$ret['path'] = $this->path();
		$ret['prev'] = $this->prev();
		$ret['terms'] = $this->terms();
		$ret['tags'] = $this->tags();
		$ret['thumbnail'] = $this->thumbnail();
		$ret['title'] = $this->title();
		return $ret;
	}

	/**
	 * @api
	 * @return bool|TimberUser
	 */
	public function author() {
		return $this->get_author();
	}

	/**
	 * @return bool|TimberUser
	 */
	public function modified_author() {
		return $this->get_modified_author();
	}

	/**
	 * @api
	 * Get the categoires on a particular post
	 * @return array of TimberTerms
	 */
	public function categories() {
		return $this->get_terms('category');
	}

	/**
	 * @api
	 * Returns a category attached to a post
	 * If mulitpuile categories are set, it will return just the first one
	 * @return TimberTerm|null
	 */
	public function category() {
		return $this->get_category();
	}

	/**
	 * Get the children of a post
	 * @api
	 * ```twig
	 * {% if post.children %}
	 *     Here are the child pages:
	 *     {% for child in page.children %}
	 *         <a href="{{ child.link }}">{{ child.title }}</a>
	 *     {% endfor %}
	 * {% endif %}
	 * ```
	 * @param string $post_type use to find children of a particular post type (attachment vs. page for example)
	 * @param string|bool $childPostClass a custom post class (ex: 'MyTimberPost') to return the objects as
	 *                                    by default (false) it will use TimberPost::$post_class value
	 * @return array
	 */
	public function children( $post_type = 'any', $childPostClass = false ) {
		return $this->get_children( $post_type, $childPostClass );
	}

	/**
	 * Get the comments on your post
	 * @api
	 * ```twig
	 * My Post's Comments:
	 * {% for comment in post.comments %}
	 *     <div class="comment comment-id-{{comment.id}}">
	 *         <p>{{ comment.content }}</p>
	 *     </div>
	 * {% endfor %}
	 * ```
	 * @return bool|array
	 */
	public function comments() {
		return $this->get_comments();
	}

	/**
	 * Gets the actual content of a WP Post, as opposed to post_content this will run the hooks/filters attached to the_content
	 * ```twig
	 * <div class="article">
	 *     {{ post.content }}
	 * </div>
	 * ```
	 * @param int $page
	 * @return string
	 */
	public function content($page = 0) {
		return $this->get_content(0, $page);
	}

	/**
	 * @return string
	 */
	public function paged_content() {
		return $this->get_paged_content();
	}

	/**
	 * Get the date to use in your template
	 * @api
	 * ```twig
	 * Published on {{ post.date }} // Uses WP's formatting set in Admin
	 * OR
	 * Published on {{ post.date|date(' F jS ')}} // Jan 12th
	 * ```
	 * @param string $date_format
	 * @return string
	 */
	public function date($date_format = '') {
		return $this->get_date($date_format);
	}

	/**
	 * @return bool|string
	 */
	public function edit_link() {
		return $this->get_edit_url();
	}

	/**
	 * @return mixed
	 */
	public function format() {
		return $this->get_format();
	}

	/**
	 * get the permalink for a post object
	 * @api
	 * ```twig
	 * <a href="{{post.link}}">Read my post</a>
	 * ```
	 * @return string ex: http://example.org/2015/07/my-awesome-post
	 */
	public function link() {
		return $this->get_permalink();
	}

	/**
	 * @param string $field_name
	 * @return mixed
	 */
	public function meta($field_name = null) {
		if ($field_name == null) {
			$field_name = 'meta';
		}
		return $this->get_field($field_name);
	}

	/**
	 * @return string
	 */
	public function name(){
		return $this->title();
	}

	/**
	 * @param string $date_format
	 * @return string
	 */
	public function modified_date($date_format = '') {
		return $this->get_modified_date($date_format);
	}

	/**
	 * @param string $time_format
	 * @return string
	 */
	public function modified_time($time_format = '') {
		return $this->get_modified_time($time_format);
	}

	/**
	 * @param bool $in_same_cat
	 * @return mixed
	 */
	public function next($in_same_cat = false) {
		return $this->get_next($in_same_cat);
	}

	/**
	 * @return array
	 */
	public function pagination() {
		return $this->get_pagination();
	}

	/**
	 * Gets the parent (if one exists) from a post as a TimberPost object (or whatever is set in TimberPost::$PostClass)
	 * ```twig
	 * Parent page: <a href="{{ post.parent.link }}">{{ post.parent.title }}</a>
	 * ```
	 * @return bool|TimberPost
	 */
	public function parent() {
		return $this->get_parent();
	}

	/**
	 * Gets the relative path of a WP Post, so while link() will return http://example.org/2015/07/my-cool-post
	 * this will return just /2015/07/my-cool-post
	 * ```twig
	 * <a href="{{post.path}}">{{post.title}}</a>
	 * ```
	 * @return string
	 */
	public function path() {
		return $this->get_path();
	}

	/**
	 * @deprecated use link() instead
	 * @return string
	 */
	public function permalink() {
		return $this->get_permalink();
	}

	/**
	 * Get the previous post in a set
	 * @api
	 * ```twig
	 * <h4>{{ post.prev.title}}</h4>
	 * ```
	 * @param bool $in_same_cat
	 * @return mixed
	 */
	public function prev($in_same_cat = false) {
		return $this->get_prev($in_same_cat);
	}

	/**
	 * Get the terms associated with the post
	 * This goes across all taxonomies by default
	 * @api
	 * @param string $tax
	 * @return array
	 */
	public function terms($tax = '') {
		return $this->get_terms($tax);
	}

	/**
	 * Gets the tags on a post, uses WP's post_tag taxonomy
	 * @api
	 * @return array
	 */
	public function tags() {
		return $this->get_tags();
	}

	/**
	 * get the featured image as a TimberImage
	 * @api
	 * ```twig
	 * <img src="{{post.thumbnail.src}}" />
	 * ```
	 * @return null|TimberImage
	 */
	public function thumbnail() {
		return $this->get_thumbnail();
	}

	/**
	 * Returns the processed title to be used in templates
	 * @api
	 * @return string
	 */
	public function title() {
		return $this->get_title();
	}

}
