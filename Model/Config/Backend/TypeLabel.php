<?php
/**
 * Created by PhpStorm.
 * User: dev
 * Date: 12/7/18
 * Time: 8:15 AM
 */

namespace HawkSearch\Proxy\Model\Config\Backend;

class TypeLabel extends \Magento\Config\Model\Config\Backend\Serialized\ArraySerialized
{

    /**
     * @var \HawkSearch\Proxy\Helper\Data
     */
    private $helper;

    public function __construct(\HawkSearch\Proxy\Helper\Data $hawkHelper,
                                \Magento\Framework\Model\Context $context,
                                \Magento\Framework\Registry $registry,
                                \Magento\Framework\App\Config\ScopeConfigInterface $config,
                                \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
                                \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
                                \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
                                array $data = []
    )
    {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->helper = $hawkHelper;
    }

    protected function _afterLoad()
    {
        parent::_afterLoad();
        if(count($this->getValue()) == 0) {
            $client = new \Zend_Http_Client();
            $client->setUri($this->helper->getTrackingUrl() . '/?' . http_build_query(['q' => '', 'hawktabs' => 'json', 'it' => 'all', 'output' => 'custom']));
            $response = $client->request();
            if($response->getStatus() != 200) {
                return;
            }
            $result = json_decode($response->getBody());
            $tabs = json_decode($result->Data->Tabs);
            $value = [];
            foreach ($tabs->Tabs as $tab) {
                if($tab->Value == 'all') {
                    continue;
                }
                $value['_' . $tab->Value] = ['title' => $tab->Title, 'code' => $tab->Value, 'color' => $this->generateColor($tab->Value)];
            }
            $this->setValue($value);
        }
    }

    private function generateColor($value)
    {
        return sprintf('#%s', substr(md5($value), 0, 6));
    }

}