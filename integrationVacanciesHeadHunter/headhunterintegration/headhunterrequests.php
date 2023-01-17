<?php

namespace Sitecore\HeadHunterIntegration;

use SiteCore\Core;
use Exception;
class HeadHunterRequests
{
    /**
     * @param $url
     * @return array
     */
    public static function getCurl($url): array
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['User-Agent:TEST', 'Authorization: Bearer ' . CORE::HH_API_TOKEN]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        $info = curl_getinfo($curl);
        if ($info['http_code'] !== 200) {
            if (\CModule::IncludeModule('logs')) {
                $logger = new Logs\Log('HH_Request_Failed');
                $logger->error('Request failed', ['Request failed with http code ' . $info['http_code'] . ': ' . $result]);
            }
          curl_close($curl);
        }
        return json_decode($result, true);

    }
}
?>
