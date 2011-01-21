<?php

/**
 * Documentation Search powered by Lucene. You will need Zend_Lucene installed on your path
 * to rebuild the indexes run the {@link RebuildLuceneDocsIndex} task. You may wish to setup
 * a cron job to remake the indexes on a regular basis.
 *
 * It has the ability to generate an OpenSearch RSS formatted feeds simply by using the URL
 *
 * <code>
 * yoursite.com/search/?q=Foo&format=rss. // Format can either be specified as rss or left off.
 * </code>
 *
 * To get a specific amount of results you can also use the modifiers start and limit
 *
 * <code>
 * yoursite.com/search/?q=Foo&start=10&limit=10
 * </code>
 *
 * @package sapphiredocs
 */

class DocumentationSearch extends Controller {
	
	/**
	 * @var bool - Is search enabled
	 */
	private static $enabled = false;
	
	/**
	 * @var string - OpenSearch metadata. Please use {@link DocumentationSearch::set_meta_data()}
	 */
	private static $meta_data;
	
	/**
	 * @var DataObjectSet - Results
	 */
	private $results;
	
	/**
	 * @var int
	 */
	private $totalResults;
	
	/**
	 * @var string
	 */
	private $query;
	
	/**
	 * @var Controller
	 */
	private $outputController;
	
	/**
	 * Set the current search query
	 *
	 * @param string
	 */
	public function setQuery($query) {
		$this->query = $query;
	}
	
	/**
	 * Returns the current search query
	 *
	 * @return string
	 */
	public function getQuery() {
		return $this->query;
	}
	
	/**
	 * Sets the {@link DocumentationViewer} or {@link DocumentationSearch} instance which this search is rendering
	 * on based on whether it is the results display or RSS feed
	 *
	 * @param Controller
	 */
	public function setOutputController($controller) {
		$this->outputController = $controller;
	}
	
	/**
	 * Folder name for indexes (in the temp folder). You can override it using
	 * {@link DocumentationSearch::set_index_location($)}
	 *
	 * @var string 
	 */
	private static $index_location = 'sapphiredocs';
	
	static $allowed_actions = array(
		'buildindex',
		'opensearch'
	);
	
	/**
	 * Generate an array of every single documentation page installed on the system. 
	 *
	 * @return DataObjectSet
	 */
	public static function get_all_documentation_pages() {
		DocumentationService::load_automatic_registration();
		
		$modules = DocumentationService::get_registered_modules();
		$output = new DataObjectSet();

		if($modules) {
			foreach($modules as $module) {
				
				foreach($module->getLanguages() as $language) {
					try {
						$pages = DocumentationService::get_pages_from_folder($module);
						
						if($pages) {
							foreach($pages as $page) {
								$output->push($page);
							}
						}
					}
					catch(Exception $e) {
						user_error($e, E_USER_WARNING);
					}
				}
			}
		}

		return $output;
	}

	/**
	 * Enable searching documentation 
	 */
	public static function enable() {
		self::$enabled = true;
		
		// include the zend search functionality
		set_include_path(get_include_path() . PATH_SEPARATOR . dirname(dirname(__FILE__)) . '/thirdparty/');
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return self::$enabled;
	}

	/**
	 * @param string
	 */
	public function set_index($index) {
		self::$index_location = $index;
	}
	
	/**
	 * @return string
	 */
	public function get_index_location() {
		return TEMP_FOLDER . '/'. trim(self::$index_location, '/');
	}
	
	/**
	 * Perform a search query on the index
	 */
	public function performSearch() {	
		try {
			$index = Zend_Search_Lucene::open(self::get_index_location());
		
			Zend_Search_Lucene::setResultSetLimit(200);
		
			$this->results = $index->find($this->getQuery());
			$this->totalResults = $index->numDocs();
		}
		catch(Zend_Search_Lucene_Exception $e) {
			user_error('DocumentationSearch::performSearch() could not perform search as index does not exist. 
				Please run /dev/tasks/RebuildLuceneDocsIndex', E_USER_ERROR);
		}
	}
	
	/**
	 * @return ArrayData
	 */
	public function getSearchResults($request) {
		$pageLength = (isset($_GET['length'])) ? (int) $_GET['length'] : 10;
		
		$data = array(
			'Results' => null,
			'Query' => null,
			'Title' => _t('DocumentationSearch.SEARCHRESULTS', 'Search Results'),
			'TotalResults' => null,
			'TotalPages' => null,
			'ThisPage' => null,
			'StartResult' => null,
			'PageLength' => $pageLength,
			'EndResult' => null,
			'PrevUrl' => DBField::create('Text', 'false'),
			'NextUrl' => DBField::create('Text', 'false'),
			'SearchPages' => new DataObjectSet()
		);
	
		$start = ($request->requestVar('start')) ? (int)$request->requestVar('start') : 0;
		$query = ($request->requestVar('Search')) ? $request->requestVar('Search') : '';
		

		$currentPage = floor( $start / $pageLength ) + 1;
		
		$totalPages = ceil(count($this->results) / $pageLength );
		
		if ($totalPages == 0) $totalPages = 1;
		if ($currentPage > $totalPages) $currentPage = $totalPages;

		$results = new DataObjectSet();
		
		foreach($this->results as $k => $hit) {
			if($k < ($currentPage-1)*$pageLength || $k >= ($currentPage*$pageLength)) continue;
			
			$doc = $hit->getDocument();
			
			$content = $hit->content;
			
			// do a simple markdown parse of the file
			$obj = new ArrayData(array(
				'Title' => DBField::create('Varchar', $doc->getFieldValue('Title')),
				'Link' => DBField::create('Varchar',$doc->getFieldValue('Link')),
				'Language' => DBField::create('Varchar',$doc->getFieldValue('Language')),
				'Version' => DBField::create('Varchar',$doc->getFieldValue('Version')),
				'Content' => DBField::create('HTMLText', $content),
				'Score' => $hit->score,
				'Number' => $k + 1
			));

			$results->push($obj);
		}

		$data['Results'] = $results;
		$data['Query']   = DBField::create('Text', $query);
		$data['TotalResults'] = DBField::create('Text', count($this->results));
		$data['TotalPages'] = DBField::create('Text', $totalPages);
		$data['ThisPage'] = DBField::create('Text', $currentPage);
		$data['StartResult'] = $start + 1;
		$data['EndResult'] = $start + count($results);

		// Pagination links
		if($currentPage > 1) {
			$data['PrevUrl'] = DBField::create('Text', 
				$this->buildQueryUrl(array('start' => ($currentPage - 2) * $pageLength))
			);
		}

		if($currentPage < $totalPages) {
			$data['NextUrl'] = DBField::create('Text', 
				$this->buildQueryUrl(array('start' => $currentPage * $pageLength))
			);
		}
		
		if($totalPages > 1) {
			// Always show a certain number of pages at the start
			for ( $i = 1; $i <= $totalPages; $i++ ) {
				$obj = new DataObject();
				$obj->IsEllipsis = false;
				$obj->PageNumber = $i;
				$obj->Link = $this->buildQueryUrl(array(
					'start' => ($i - 1) * $pageLength
				));
				
				$obj->Current = false;
				if ( $i == $currentPage ) $obj->Current = true;
				$data['SearchPages']->push($obj);
			}
		}

		return new ArrayData($data);
	}
	
	/**
	 * Build a nice query string for the results
	 *
	 * @return string
	 */
	private function buildQueryUrl($params) {
		$url = parse_url($_SERVER['REQUEST_URI']);
		if ( ! array_key_exists('query', $url) ) $url['query'] = '';
		parse_str($url['query'], $url['query']);
		if ( ! is_array($url['query']) ) $url['query'] = array();
		// Remove 'start parameter if it exists
		if ( array_key_exists('start', $url['query']) ) unset( $url['query']['start'] );
		// Add extra parameters from argument
		$url['query'] = array_merge($url['query'], $params);
		$url['query'] = http_build_query($url['query']);
		$url = $url['path'] . ($url['query'] ? '?'.$url['query'] : '');
		
		return $url;
	}
	
	/**
	 * @return int
	 */
	public function getTotalResults() {
		return (int) $this->totalResults;
	}

	/**
	 * Optimizes the search indexes on the File System
	 *
	 * @return void
	 */
	public function optimizeIndex() {
		$index = Zend_Search_Lucene::open(self::get_index_location());

		if($index) $index->optimize();
	}
	
	public function getTitle() {
		return ($this->outputController) ? $this->outputController->Title : "";
	}
	
	/**
	 * OpenSearch MetaData. Includes 'description', 'tags', 'contact'
	 *
	 * @param array
	 */
	public static function set_meta_data($data) {
		if(is_array($data)) {
			foreach($data as $key => $value) {
				self::$meta_data[strtolower($key)] = $value;
			}
		}
	}
	
	/**
	 * Returns the meta data needed by opensearch.
	 *
	 * @return array
	 */
	public static function get_meta_data() {
		$data = self::$meta_data;
		
		return array(
			'Description' => (isset($data['description'])) ? $data['description'] : "",
			'Tags' => (isset($data['tags'])) ? $data['tags'] : "",
			'Contact' => (isset($data['contact'])) ? $data['contact'] : "",
			'ShortName' => (isset($data['shortname'])) ? $data['shortname'] : ""
		);
	}
	
	public function renderResults() {
		if(!$this->results) $this->performSearch();
		if(!$this->outputController) $this->outputController = $this;
		
		$request = $this->outputController->getRequest();
		
		$data = $this->getSearchResults($request);
		$templates = array('DocumentationViewer_results', 'DocumentationViewer');

		if($request->requestVar('format') && $request->requestVar('format') == "rss") {
			// alter the fields for the opensearch xml.
			$title = ($title = $this->getTitle()) ? ' | '. $title : "";
			
			$data->setField('Title', $data->Title . $title);

			$data->setField('DescriptionURL', 'DocumentationSearch/opensearch/');
			
			array_unshift($templates, 'OpenSearchResults');
		}
		
		return $this->outputController->customise($data)->renderWith($templates);
	}
	
	/**
	 * Returns the opensearch description of the search results
	 */
	public function opensearch() {
		$data = self::get_meta_data();
		
		return $this->customise(new ArrayData($data))->renderWith(array('OpenSearchDescription'));
	}
}