<?php
/**
 * Copyright (c) 2020 Hawksearch (www.hawksearch.com) - All Rights Reserved
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */

namespace HawkSearch\Proxy\Helper;

use Composer\Util\Filesystem as UtilFileSystem;
use Exception;
use HawkSearch\Connector\Gateway\Instruction\InstructionManagerPool;
use HawkSearch\Connector\Gateway\InstructionException;
use HawkSearch\Proxy\Api\Data\SearchResultResponseInterface;
use HawkSearch\Proxy\Model\ConfigProvider;
use HawkSearch\Proxy\Model\ProxyEmailFactory;
use HawkSearch\Proxy\Model\SearchResultBanner;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\SessionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Framework\App\CacheInterface as Cache;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\Io\File as ioFile;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ResourceModel\Store\CollectionFactory as StoreCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Zend_Http_Client;

class Data extends AbstractHelper
{
    /**#@+
     * Config xml path constants
     */
    const CONFIG_PROXY_CATEGORY_SYNC_CRON_ENABLED = 'hawksearch_proxy/sync/enabled';
    const CONFIG_PROXY_SHOWTABS = 'hawksearch_proxy/proxy/show_tabs';
    const CONFIG_PROXY_TYPE_LABEL = 'hawksearch_proxy/proxy/type_label';
    const CONFIG_PROXY_SHOW_TYPE_LABELS = 'hawksearch_proxy/proxy/show_type_labels';
    /**#@-*/

    const HAWK_LANDING_PAGE_URL = 'LandingPage/';
    const LP_CACHE_KEY = 'hawk_landing_pages';
    const LOCK_FILE_NAME = 'hawkcategorysync.lock';

    /**
     * @var array
     */
    protected $_syncingExceptions = [];

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var SearchResultResponseInterface
     */
    private $hawkData;

    /**
     * @var
     */
    protected $uri;

    /**
     * @var bool
     */
    private $isManaged;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Monolog $logger
     */
    private $overwriteFlag;

    /**
     * @var ProxyEmailFactory
     */
    private $email_helper;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var SessionFactory
     */
    protected $session;

    /**
     * @var Config
     */
    private $catalogConfig;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * @var StoreCollectionFactory
     */
    private $storeCollectionFactory;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var UrlFinderInterface
     */
    private $urlFinder;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var File
     */
    protected $file;

    /**
     * @var ioFile
     */
    protected $fileDirectory;

    /**
     * @var UtilFileSystem
     */
    private $utilFileSystem;

    /**
     * @var InstructionManagerPool
     */
    private $instructionManagerPool;

    /**
     * @var ConfigProvider
     */
    private $proxyConfigProvider;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     * @param ProxyEmailFactory $email_helper
     * @param CollectionFactory $collectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param Config $catalogConfig
     * @param Emulation $emulation
     * @param StoreCollectionFactory $storeCollectionFactory
     * @param Cache $cache
     * @param SessionFactory $session
     * @param UrlFinderInterface $urlFinder
     * @param Escaper $escaper
     * @param SerializerInterface $serializer
     * @param File $file
     * @param ioFile $fileDirectory
     * @param UtilFileSystem $utilFileSystem
     * @param InstructionManagerPool $instructionManagerPool
     * @param ConfigProvider $proxyConfigProvider
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        Filesystem $filesystem,
        ProxyEmailFactory $email_helper,
        CollectionFactory $collectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        Config $catalogConfig,
        Emulation $emulation,
        StoreCollectionFactory $storeCollectionFactory,
        Cache $cache,
        SessionFactory $session,
        UrlFinderInterface $urlFinder,
        Escaper $escaper,
        SerializerInterface $serializer,
        File $file,
        ioFile $fileDirectory,
        UtilFileSystem $utilFileSystem,
        InstructionManagerPool $instructionManagerPool,
        ConfigProvider $proxyConfigProvider
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
        $this->collectionFactory = $collectionFactory;
        $this->session = $session;
        $this->catalogConfig = $catalogConfig;

        $this->overwriteFlag = false;
        $this->email_helper = $email_helper;

        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->emulation = $emulation;
        $this->storeCollectionFactory = $storeCollectionFactory;
        $this->cache = $cache;
        $this->urlFinder = $urlFinder;
        $this->escaper = $escaper;
        $this->serializer = $serializer;
        $this->file = $file;
        $this->fileDirectory = $fileDirectory;
        $this->utilFileSystem = $utilFileSystem;
        $this->instructionManagerPool = $instructionManagerPool;
        $this->proxyConfigProvider = $proxyConfigProvider;
    }

    /**
     * @param string $data
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function getConfigurationData($data)
    {
        $storeScope = ScopeInterface::SCOPE_STORE;

        return $this->scopeConfig->getValue($data, $storeScope, $this->storeManager->getStore()->getCode());
    }

    /**
     * @throws InstructionException
     * @throws NotFoundException
     */
    private function fetchResponse()
    {
        $this->hawkData = $this->instructionManagerPool->get('hawksearch')
            ->executeByCode('searchResults', $this->_getRequest()->getParams())->get();

        return;
    }

    /**
     * @return SearchResultResponseInterface
     * @throws InstructionException
     * @throws NotFoundException
     */
    public function getResultData()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }
        return $this->hawkData;
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        $apiUrl = rtrim($this->proxyConfigProvider->getHawkUrlHost(), '/');
        return $apiUrl . '/api/v3/';
    }

    /**
     * @return string|null
     * @throws InstructionException
     * @throws NotFoundException
     */
    public function getLocation()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }
        return $this->hawkData->getLocation();
    }

    /**
     * @return string
     * @throws InstructionException
     * @throws LocalizedException
     * @throws NotFoundException
     */
    public function getTrackingDataHtml()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }
        $counter = 1;
        $obj = [];
        $productCollection = $this->getProductCollection();
        if ($productCollection instanceof Collection) {
            foreach ($productCollection as $item) {
                $obj[] = ['url' => $item->getProductUrl(),
                    'tid' => $this->hawkData->getTrackingId(), 'sku' => $item->getSku(), 'i' => $counter++];
            }
            return sprintf(
                '<div id="hawktrackingdata" style="display:none;" data-tracking="%s"></div>',
                $this->escaper->escapeHtml(json_encode($obj, JSON_UNESCAPED_SLASHES), ENT_QUOTES)
            );
        }
        return '<div id="hawktrackingdata" style="display:none;" data-tracking="[]"></div>';
    }

    /**
     * @return string|null
     * @throws InstructionException
     * @throws NotFoundException
     */
    public function getFacets()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }
        return $this->hawkData->getResponseData()->getFacets();
    }

    /**
     * @return string|null
     * @throws NoSuchEntityException
     */
    public function getApiKey()
    {
        return $this->getConfigurationData('hawksearch_proxy/proxy/hawksearch_api_key');
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function productsOnly()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }

        if ($this->hawkData->getResponseData()->getResults()->getItems()) {
            foreach ($this->hawkData->getResponseData()->getResults()->getItems() as $item) {
                $itemCustomData = $item->getCustom();
                if (!isset($itemCustomData['sku'])) {
                    return false;
                }
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return Collection|null
     * @throws LocalizedException
     */
    public function getProductCollection()
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }

        $skus = [];
        $map = [];
        $bySku = [];
        $i = 0;
        if (!$this->hawkData->getResponseData()->getResults()->getItems()) {
            return $this->getResourceCollection([]);
        }
        foreach ($this->hawkData->getResponseData()->getResults()->getItems() as $item) {
            $itemCustomData = $item->getCustom();
            if (isset($itemCustomData['sku'])) {
                $skus[] = $itemCustomData['sku'];
                $map[$itemCustomData['sku']] = $i;
                $bySku[$itemCustomData['sku']] = $item;
                $i++;
            }
        }
        if (empty($skus)) {
            return null;
        }

        $collection = $this->getResourceCollection($skus);

        $sorted = [];
        if ($collection->count() > 0) {
            $it = $collection->getIterator();
            while ($it->valid()) {
                $prod = $it->current();
                $sorted[$map[trim($prod->getSku())]] = $prod;
                $it->next();
            }
            ksort($sorted);
            foreach ($sorted as $p) {
                $p->setHawkItem($bySku[$p->getSku()]);
                $collection->removeItemByKey($p->getId());
                $collection->addItem($p);
            }
        }

        return $collection;
    }

    /**
     * @param string $zone
     * @return Collection|null
     * @throws InstructionException
     * @throws LocalizedException
     * @throws NotFoundException
     */
    public function getFeaturedProductCollection($zone)
    {
        if (empty($this->hawkData)) {
            $this->fetchResponse();
        }
        $skus = [];
        $map = [];
        $i = 0;

        if (!$this->hawkData->getResponseData()->getFeaturedItems()->getItems()) {
            return null;
        } else {
            foreach ($this->hawkData->getResponseData()->getFeaturedItems()->getItems() as $banner) {
                /** @var SearchResultBanner $banner */
                if ($banner->getZone() == $zone && $banner->getItems()) {
                    foreach ($banner->getItems() as $item) {
                        if (isset($item->getCustom()['sku'])) {
                            $skus[] = $item->getCustom()['sku'];
                            $map[$item->getCustom()['sku']] = $i;
                            $i++;
                        }
                    }
                }
            }
        }

        $productCollection = $this->collectionFactory->create();
        $collection = $productCollection
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addAttributeToFilter('sku', ['in' => $skus])
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();

        $sorted = [];
        if ($collection->count() > 0) {
            $it = $collection->getIterator();
            while ($it->valid()) {
                $prod = $it->current();
                $sorted[$map[trim($prod->getSku())]] = $prod;
                $it->next();
            }
            ksort($sorted);
            foreach ($sorted as $p) {
                $collection->removeItemByKey($p->getId());
                $collection->addItem($p);
            }
        }

        return $collection;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string|resource $data
     * @return string
     */
    public function getHawkResponse($method, $url, $data = null)
    {
        try {
            $client = new Zend_Http_Client();
            $client->setConfig(['timeout' => 60]);
            $client->setUri($this->getApiUrl() . $url);
            $client->setMethod($method);
            if (isset($data)) {
                $client->setRawData($data, 'application/json');
            }
            $client->setHeaders('X-HawkSearch-ApiKey', $this->getApiKey());
            $client->setHeaders('Accept', 'application/json');
            $this->log(sprintf('fetching request. URL: %s, Method: %s', $client->getUri(), $method));
            $response = $client->request();
            return $response->getBody();
        } catch (Exception $e) {
            $this->log($e);
            return json_encode(['Message' => "Internal Error - " . $e->getMessage()]);
        }
    }

    /**
     * @return string
     * @throws NoSuchEntityException
     */
    public function getLPCacheKey()
    {
        return self::LP_CACHE_KEY . $this->storeManager->getStore()->getId();
    }

    /**
     * @param bool $force
     * @return array
     * @throws NoSuchEntityException
     */
    public function getLandingPages($force = false)
    {
        if (($serialized = $this->cache->load($this->getLPCacheKey()))) {
            $landingPages = $this->serializer->unserialize($serialized);
        } else {
            $landingPages = json_decode($this->getHawkResponse(Zend_Http_Client::GET, 'LandingPage/Urls'));
            sort($landingPages, SORT_STRING);
            $this->cache->save($this->serializer->serialize($landingPages), $this->getLPCacheKey(), [], 300);
        }
        return $landingPages;
    }

    /**
     * @param bool $im
     */
    public function setIsHawkManaged($im)
    {
        $this->isManaged = $im;
    }

    /**
     * @param string|null $path
     * @return bool
     */
    public function getIsHawkManaged($path = null)
    {
        if (empty($path)) {
            return $this->isManaged;
        }

        $path = '/' . rtrim(ltrim($path, '/'), '/');

        if (in_array($path, ['/catalogsearch/result', '/hawkproxy'])
            && $this->proxyConfigProvider->isSearchManagementEnabled()
        ) {
            return true;
        }

        $this->isManaged = $this->isManagedLandingPage($path);
        return $this->isManaged;
    }

    /**
     * @param string $pageUrl
     * @return bool
     * @throws NoSuchEntityException
     */
    private function isManagedLandingPage($pageUrl)
    {
        $pageUrl = '/' . rtrim(ltrim($pageUrl, '/'), '/');

        $landingPages = $this->getLandingPages();
        $lowIndex = 0;
        $highIndex = count($landingPages) - 1;
        $isManaged = false;

        /*
         * Search through an alphabetically sorted list of page URLs
         */
        while ($lowIndex <= $highIndex) {
            $floorAverage = (int)floor(($highIndex + $lowIndex) / 2);
            $comparisonResult = strcmp($landingPages[$floorAverage], $pageUrl);
            if ($comparisonResult == 0) {
                $isManaged = true;
                break;
            } elseif ($comparisonResult < 0) {
                $lowIndex = $floorAverage + 1;
            } else {
                $highIndex = $floorAverage - 1;
            }
        }
        return $isManaged;
    }

    /**
     * @param string $name
     * @param string $url
     * @param string $xml
     * @param string $cid
     * @param bool $clear
     * @return array
     */
    private function getLandingPageObject($name, $url, $xml, $cid, $clear = false)
    {
        $custom = '';
        if (!$clear) {
            $custom = "__mage_catid_{$cid}__";
        }
        return [
            'PageId' => 0,
            'Name' => $name,
            'CustomUrl' => $url,
            'IsFacetOverride' => false,
            'SortFieldId' => 0,
            'SortDirection' => 'Asc',
            'SelectedFacets' => [],
            'NarrowXml' => $xml,
            'Custom' => $custom
        ];
    }

    /**
     * @param string $id
     * @return string|bool
     */
    private function getHawkNarrowXml($id)
    {
        $xml = simplexml_load_string(
            '<?xml version="1.0" encoding="UTF-8"?>
<Rule xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
RuleType="Group" Operator="All" />'
        );
        $rules = $xml->addChild('Rules');
        $rule = $rules->addChild('Rule');
        $rule->addAttribute('RuleType', 'Eval');
        $rule->addAttribute('Operator', 'None');
        $rule->addChild('Field', 'facet:category_id');
        $rule->addChild('Condition', 'is');
        $rule->addChild('Value', $id);
        $xml->addChild('Field');
        $xml->addChild('Condition');
        $xml->addChild('Value');
        return $xml->asXML();
    }

    /**
     * @param array $hawklist
     * @return array
     */
    private function createExistingCustomFieldMap($hawklist)
    {
        $a = [];
        foreach ($hawklist as $item) {
            if (isset($item['custom'])) {
                $a[$item['custom']] = $item;
            }
        }
        return $a;
    }

    /**
     * @param array $lpObject
     * @param array $existingCustom
     * @return mixed
     */
    private function clearExistingCustomField($lpObject, $existingCustom)
    {
        if (isset($existingCustom[$lpObject['Custom']])
            && $existingCustom[$lpObject['Custom']]['hawkurl'] != $lpObject['CustomUrl']
        ) {
            preg_match('/__mage_catid_(\d+)__/', $existingCustom[$lpObject['Custom']]['custom'], $matches);
            if ($matches[1]) {
                $otherObject = $this->getLandingPageObject(
                    $existingCustom[$lpObject['Custom']]['name'],
                    $existingCustom[$lpObject['Custom']]['hawkurl'],
                    $this->getHawkNarrowXml($matches[1]),
                    $matches[1],
                    true
                );
                $otherObject['PageId'] = $existingCustom[$lpObject['Custom']]['pageid'];
                $resp = $this->getHawkResponse(
                    Zend_Http_Client::PUT,
                    self::HAWK_LANDING_PAGE_URL . $otherObject['PageId'],
                    json_encode($otherObject)
                );
                $this->validateHawkLandingPageResponse(
                    $resp,
                    Zend_Http_Client::PUT,
                    $lpObject['CustomUrl'],
                    json_encode($lpObject)
                );
            }
        }
        return $lpObject['Custom'];
    }

    /**
     * @param Store $store
     * @throws LocalizedException
     */
    private function syncHawkLandingByStore(Store $store)
    {
        $this->log(sprintf('Starting environment for store %s', $store->getName()));

        $this->emulation->startEnvironmentEmulation($store->getId());
        $this->log('starting synchronizeHawkLandingPages()');
        /*
         * ok, so here is the problem, if we put or post,
         * and some landing page already has that "custom" value, we get
         * a duplicate error: {"Message":"Duplicate Custom field"}.
         * so lets create a new array "existingCustom" so we can
         * clear the custom value from the existing landing page.
         * we will need to trim that function at the end of each
         * iteration so we don't end up removing custom fields we just set */

        $hawkList = $this->getHawkLandingPages();
        $existingCustom = $this->createExistingCustomFieldMap($hawkList);
        $this->log(sprintf('got %d hawk managed landing pages', count($hawkList)));

        $mageList = $this->getMagentoLandingPages();
        $this->log(sprintf('got %d magento categories', count($mageList)));

        $this->log(sprintf('got %d magento category pages', count($mageList)));

        usort(
            $hawkList,
            function ($a, $b) {
                return strcmp($a['hawkurl'], $b['hawkurl']);
            }
        );
        usort(
            $mageList,
            function ($a, $b) {
                return strcmp($a['hawkurl'], $b['hawkurl']);
            }
        );

        $left = 0; //hawk on the left
        $right = 0; //magento on the right
        while ($left < count($hawkList) || $right < count($mageList)) {
            if ($left >= count($hawkList)) {
                //only right left to process
                $sc = 1;
            } elseif ($right >= count($mageList)) {
                // only left left to process
                $sc = -1;
            } else {
                $sc = strcmp($hawkList[$left]['hawkurl'], $mageList[$right]['hawkurl']);
            }
            $customVal = null;
            if ($sc < 0) {
                //Hawk has page Magento doesn't want managed, delete, increment left
                if (substr($hawkList[$left]['custom'], 0, strlen('__mage_catid_'))== '__mage_catid_'
                    || $this->overwriteFlag
                ) {
                    $resp = $this->getHawkResponse(
                        Zend_Http_Client::DELETE,
                        self::HAWK_LANDING_PAGE_URL . $hawkList[$left]['pageid']
                    );
                    $this->validateHawkLandingPageResponse(
                        $resp,
                        Zend_Http_Client::DELETE,
                        $hawkList[$left]['hawkurl']
                    );
                    $this->log(
                        sprintf(
                            'attempt to remove page %s resulted in: %s',
                            $hawkList[$left]['hawkurl'],
                            $resp
                        )
                    );
                } else {
                    $this->log(
                        sprintf(
                            'Customer custom landing page "%s", skipping',
                            $hawkList[$left]['hawkurl']
                        )
                    );
                }
                $customVal = $hawkList[$left]['custom'];
                $left++;
            } elseif ($sc > 0) {
                //Mage wants it managed, but hawk doesn't know, POST and increment right
                $lpObject = $this->getLandingPageObject(
                    $mageList[$right]['name'],
                    $mageList[$right]['hawkurl'],
                    $this->getHawkNarrowXml($mageList[$right]['catid']),
                    $mageList[$right]['catid']
                );
                $customVal = $this->clearExistingCustomField($lpObject, $existingCustom);
                $resp = $this->getHawkResponse(
                    Zend_Http_Client::POST,
                    self::HAWK_LANDING_PAGE_URL,
                    json_encode($lpObject)
                );
                $this->validateHawkLandingPageResponse(
                    $resp,
                    Zend_Http_Client::POST,
                    $mageList[$right]['hawkurl'],
                    json_encode($lpObject)
                );

                $this->log(
                    sprintf(
                        'attempt to add page %s resulted in: %s',
                        $mageList[$right]['hawkurl'],
                        $resp
                    )
                );
                $right++;
            } else {
                //they are the same, PUT value to cover name changes, etc. increment both sides
                $lpObject = $this->getLandingPageObject(
                    $mageList[$right]['name'],
                    $mageList[$right]['hawkurl'],
                    $this->getHawkNarrowXml($mageList[$right]['catid']),
                    $mageList[$right]['catid']
                );
                $lpObject['PageId'] = $hawkList[$left]['pageid'];
                $customVal = $this->clearExistingCustomField($lpObject, $existingCustom);

                $resp = $this->getHawkResponse(
                    Zend_Http_Client::PUT,
                    self::HAWK_LANDING_PAGE_URL . $hawkList[$left]['pageid'],
                    json_encode($lpObject)
                );
                $this->validateHawkLandingPageResponse(
                    $resp,
                    Zend_Http_Client::PUT,
                    $hawkList[$left]['hawkurl'],
                    json_encode($lpObject)
                );

                $this->log(
                    sprintf(
                        'attempt to update page %s resulted in %s',
                        $hawkList[$left]['hawkurl'],
                        $resp
                    )
                );
                $left++;
                $right++;
            }
            if (isset($existingCustom[$customVal])) {
                unset($existingCustom[$customVal]);
            }
        }

        $this->emulation->stopEnvironmentEmulation();
    }

    /**
     * @return array
     */
    public function synchronizeHawkLandingPages()
    {
        $stores = $this->storeManager->getStores();
        $errors = [];
        foreach ($stores as $store) {
            /**
             * @var Store $store
             */
            if ($store->getConfig('hawksearch_proxy/general/enabled') && $store->isActive()) {
                try {
                    $this->syncHawkLandingByStore($store);
                } catch (Exception $e) {
                    $errors[] = sprintf("Error syncing category pages for store '%s'", $store->getCode());
                    $errors[] = sprintf("Exception message: %s", $e->getMessage());
                    continue;
                }
            }
        }
        return $errors;
    }

    /**
     * @return array
     */
    public function getHawkLandingPages()
    {
        $hawkPages = [];
        $pages = json_decode($this->getHawkResponse(Zend_Http_Client::GET, 'LandingPage'));
        foreach ($pages as $page) {
            if (empty($page->Custom) && !$this->overwriteFlag) {
                continue;
            }
            $hawkPages[] = [
                'pageid' => $page->PageId,
                'hawkurl' => $page->CustomUrl,
                'name' => $page->Name,
                'custom' => $page->Custom
            ];
        }

        return $hawkPages;
    }

    /**
     * @return array
     * @throws LocalizedException
     */
    public function getMagentoLandingPages()
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'is_active', 'parent_id', 'position', 'include_in_menu']);
        $collection->addAttributeToFilter('is_active', ['eq' => '1']);
        $collection->addAttributeToSort('entity_id')->addAttributeToSort('parent_id')->addAttributeToSort('position');
        $collection->addAttributeToFilter('level', ['gteq' => '2']);
        if (!$this->getManageAll()) {
            $collection->addAttributeToFilter('hawk_landing_page', ['eq' => '1']);
        }

        $collection->joinUrlRewrite();
        $collection->setPageSize(1000);
        $pages = $collection->getLastPageNumber();
        $currentPage = 1;
        $cats = [];

        do {
            $collection->clear();
            $collection->setCurPage($currentPage);
            $collection->load();
            foreach ($collection as $cat) {
                $cats[] = [
                    'hawkurl' => sprintf("/%s", $this->getRequestPath($cat)),
                    'name' => $cat->getName(),
                    'catid' => $cat->getId(),
                    'pid' => $cat->getParentId()
                ];
            }
            $currentPage++;
        } while ($currentPage <= $pages);

        return $cats;
    }

    /**
     * @param Category $category
     * @return string|null
     */
    protected function getRequestPath(Category $category)
    {
        if ($category->hasData('request_path') && $category->getRequestPath() != null) {
            return $category->getRequestPath();
        }
        $rewrite = $this->urlFinder->findOneByData(
            [
            UrlRewrite::ENTITY_ID => $category->getId(),
            UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::STORE_ID => $category->getStoreId(),
            ]
        );
        if ($rewrite) {
            return $rewrite->getRequestPath();
        }
        return null;
    }

    /**
     * @param string $message
     * @throws NoSuchEntityException
     */
    public function log($message)
    {
        if ($this->isLoggingEnabled()) {
            $this->_logger->addDebug($message);
        }
    }

    /**
     * @return bool
     * @throws NoSuchEntityException
     */
    public function getManageAll()
    {
        return !!$this->getConfigurationData('hawksearch_proxy/proxy/manage_all');
    }

    /**
     * @return bool
     * @throws NoSuchEntityException
     */
    public function isLoggingEnabled()
    {
        return !!$this->getConfigurationData('hawksearch_proxy/general/logging_enabled');
    }

    /**
     * @param string $response
     * @param string $action
     * @param string $url
     * @param string|null $request_raw
     */
    private function validateHawkLandingPageResponse($response, $action, $url, $request_raw = null)
    {
        // valid response
        $res = json_decode($response, true);
        if (isset($res['Message'])) {
            // valid action
            switch ($action) {
                case Zend_Http_Client::PUT:
                    $act = 'Landing page: Update';
                    break;
                case Zend_Http_Client::POST:
                    $act = 'Landing page: Create New';
                    break;
                case Zend_Http_Client::DELETE:
                    $act = 'Landing page: Delete';
                    break;
                default:
                    $act = "Unknown action ({$action})";
            }

            $this->_syncingExceptions[] = [
                'action' => $act,
                'url' => $url,
                'request_raw' => $request_raw,
                'error' => $res['Message']
            ];
        }
    }

    /**
     * @return string
     * @throws FileSystemException
     * @throws NoSuchEntityException
     */
    public function getSyncFilePath()
    {
        $this->log('getting sync lock file path');
        $relPath = \HawkSearch\Datafeed\Model\ConfigProvider::DEFAULT_FEED_PATH;

        $mediaRoot = $this->filesystem->getDirectoryWrite('media')->getAbsolutePath();

        if (strpos(strrev($mediaRoot), '/') !== 0) {
            $fullPath = implode(DIRECTORY_SEPARATOR, [$mediaRoot, $relPath]);
        } else {
            $fullPath = $mediaRoot . $relPath;
        }

        if (!$this->fileDirectory->fileExists($fullPath)) {
            $this->fileDirectory->mkdir($fullPath, 0777, true);
        }

        return $fullPath;
    }

    /**
     * @return bool
     */
    public function isCategorySyncCronEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PROXY_CATEGORY_SYNC_CRON_ENABLED);
    }

    /**
     * @return bool
     */
    protected  function hasExceptions()
    {
        return count($this->_syncingExceptions) > 0;
    }

    /**
     * @return string
     */
    protected function _getEmailExtraHtml()
    {
        if ($this->hasExceptions()) {
            $limit = 50;
            $html = "<p><strong>Exception logs</strong> (limited at {$limit}):</p>";

            for ($i = 0; $i <= $limit; $i++) {
                if (isset($this->_syncingExceptions[$i])) {
                    $html .= "<p>";
                    $html .= "<strong>Category Url:</strong>" . $this->_syncingExceptions[$i]['url'] . "<br/>";
                    $html .= "<strong>Action:</strong>" . $this->_syncingExceptions[$i]['action'] . "<br/>";
                    $html .= "<strong>Request Raw Data:</strong>" .
                        $this->_syncingExceptions[$i]['request_raw'] . "<br/>";
                    $html .= "<strong>Response Message:</strong>" . $this->_syncingExceptions[$i]['error'] . "<br/>";
                    $html .= "</p>";
                    $html .= "<hr/>";
                }
            }

            $html .= "<br/><br/>
<p><strong>Note*:</strong> Other synchronizing requests to HawkSearch were sent as successfully.</p>";

            return $html;
        }
        return '';
    }

    /**
     * @return bool
     * @throws NoSuchEntityException
     */
    public function getShowTabs()
    {
        return !!$this->getConfigurationData(self::CONFIG_PROXY_SHOWTABS);
    }

    /**
     * @param array $skus
     * @return Collection
     */
    public function getResourceCollection(array $skus)
    {
        /**
         * @var Collection $collection
         */
        $collection = $this->collectionFactory->create();
        $collection
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addAttributeToFilter('sku', ['in' => $skus])
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();
        return $collection;
    }

    /**
     * @return array
     * @throws NoSuchEntityException
     */
    public function getTypeLabelMap()
    {
        $obj = json_decode($this->getConfigurationData(self::CONFIG_PROXY_TYPE_LABEL));
        $map = [];
        if (is_object($obj)) {
            foreach ($obj as $key => $item) {
                $map[$item->code] = $item;
            }
        }
        return $map;
    }

    /**
     * @return bool
     * @throws NoSuchEntityException
     */
    public function getShowTypeLabels()
    {
        return !!$this->getConfigurationData(self::CONFIG_PROXY_SHOW_TYPE_LABELS);
    }

    /**
     * @param string $value
     * @return string
     */
    public function generateColor($value)
    {
        return sprintf('#%s', substr(sha1($value), 0, 6));
    }

    /**
     * @param string $rgb
     * @return string
     */
    public function generateTextColor($rgb)
    {
        $r = hexdec(substr($rgb, 1, 2));
        $g = hexdec(substr($rgb, 3, 2));
        $b = hexdec(substr($rgb, 5, 2));
        if (($r * 299 + $g * 587 + $b * 114) / 1000 < 123) {
            return '#fff';
        }
        return '#000';
    }

    /**
     * @param string $mode
     * @return bool
     */
    public function modeActive(string $mode)
    {
        switch ($mode) {
            case 'proxy':
                return true;
            case 'catalogsearch':
                return $this->proxyConfigProvider->isSearchManagementEnabled();
            case 'category':
                return $this->proxyConfigProvider->isCategoriesManagementEnabled();
        }
        return false;
    }
}
