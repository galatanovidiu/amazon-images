<?php
/**
 *
 */

namespace Galatanovidiu\Amazonimage;

class GetProductImageAWS implements GetProductImageInterface
{


    public function get_images($asin)
    {

        $country        = 'com';
        $search_indexes = ['All'];
        $min_image_resolution = 399;

        $images = [];

        $conf = new \ApaiIO\Configuration\GenericConfiguration();
        $conf
            ->setCountry($country)
            ->setAccessKey(env('AWS_API_KEY', ''))
            ->setSecretKey(env('AWS_API_SECRET_KEY', ''))
            ->setAssociateTag(env('AWS_ASSOCIATE_TAG', ''));


        $search = new \ApaiIO\Operations\Lookup();
        $search->setCountry($country);
        $search->setCategory($search_indexes[0]);
        $search->setItemId($asin);
        $search->setResponsegroup(['Images']);

        $apaiIo = new \ApaiIO\ApaiIO($conf);

        /**
         * Sometimes Amazon returns an error even if the request is valid
         * This will attempt to get the images 5 times before quiting
         * Pause between retries is 3 seconds
         */
        $i = 1;
        while ($i++ < 5) {
            try {
                $response = $apaiIo->runOperation($search);
                if ( ! $response) {
                    sleep(3);
                    echo "Retry get images...<br>\n ";
                    continue;
                }
                break;
            } catch (\Exception $e) {
                sleep(3);
                echo "Retry get images...<br>\n ";
            }
        }


        $response = simplexml_load_string($response);
        $response = json_decode(json_encode($response), true); // Transform response to attay

        if(isset($response['Items']['Item']['HiResImage'])){
            $main_image = $response['Items']['Item']['HiResImage'];
        } elseif(isset($response['Items']['Item']['LargeImage'])) {
            $main_image = $response['Items']['Item']['LargeImage'];
        }

        if ( isset($main_image) and $main_image['Width'] > $min_image_resolution ) {

            $image_id = md5($response['Items']['Item']['SmallImage']['URL']); //
            $images[$image_id] = $main_image;

            if (isset($response['Items']['Item']['ImageSets']['ImageSet'][0])) {
                foreach ($response['Items']['Item']['ImageSets']['ImageSet'] as $i) {

                    $is_image = '';
                    if(isset($i['HiResImage'])){
                        $is_image = $i['HiResImage'];
                    } elseif(isset($i['LargeImage'])) {
                        $is_image = $i['LargeImage'];
                    }
                    if (!empty($is_image) and $is_image['Width'] > $min_image_resolution) {
                        $is_id = md5($i['SmallImage']['URL']);
                        $images[$is_id] = $is_image;
                    }

                }
            }
        }

        return $images;
    }



    public function get_images_from_amazon_page($asin, $shoe_data = '')
    {

        //print_r($shoe_data);

        if ($shoe_data) {
            $d_asin = isset($shoe_data['ASIN']) ? $shoe_data['ASIN'] : '';
            $ParentASIN = isset($shoe_data['ParentASIN']) ? $shoe_data['ParentASIN'] : '';
            $Color = isset($shoe_data['ItemAttributes']['Color']) ? $shoe_data['ItemAttributes']['Color'] : '';
            $Department = isset($shoe_data['ItemAttributes']['Department']) ? $shoe_data['ItemAttributes']['Department'] : '';
        } else {
            $t_image = OfferImages::where('asin', $asin)->first();
            $data = json_decode($t_image->notes, true);
            if ($data['ParentASIN']) {
                $d_asin = $asin;
                $ParentASIN = isset($data['ParentASIN']) ? $data['ParentASIN'] : '';
                $Color = isset($data['Color']) ? $data['Color'] : '';
                $Department = isset($data['Department']) ? $data['Department'] : '';
            } else {
                $d_asin = $asin;
                $ParentASIN = $asin;
                $Color = '';
                $Department = '';
            }
        }

        $ti = self::get_images_data_from_amazon_page($asin);
        //echo '--';
        //print_r($ti);
        $images = [];
        if (is_array($ti)) {
            foreach ($ti as $k => $response) {
                if (isset($response['hiRes']) and $response['hiRes']) {
                    $a_id = md5($response['thumb']);

                    $images[$a_id] = [
                        'SmallImage' => ['URL' => isset($response['thumb']) ? $response['thumb'] : ''],
                        'LargeImage' => ['URL' => isset($response['large']) ? $response['large'] : ''],
                        'HiResImage' => ['URL' => isset($response['hiRes']) ? $response['hiRes'] : ''],
                        'notes'      => [
                            'ASIN'       => $d_asin,
                            'ParentASIN' => $ParentASIN,
                            'Color'      => $Color,
                            'Department' => $Department,
                            'from'       => 'amazon',
                        ],
                    ];
                }
            }
        }

        return $images;
    }

    public static function get_images_data_from_amazon_page($asin, $force = false)
    {

        //In case the images links have already been imported from amazon page return that
        if (!$force) {
            $return = ImgOffers::where('shop_pid', $asin)->first();
            if (isset($return->images_data)) {
                $images_data = json_decode($return->images_data, true);
                if (is_array($images_data)) {
                    return $images_data;
                }
            }
        }


        $link = 'http://www.amazon.com/dp/'.$asin;

        $data = self::proxy_get($link);

        $vars = self::findVar('data', $data['body']);
        $vars = preg_replace("/'(\w+)'/i", '"$1"', $vars).'}';
        $vars_array = json_decode($vars, true);

        if (isset($vars_array['colorImages']['initial']) and is_array($vars_array['colorImages']['initial'])) {
            ImgOffers::where(
                'shop_pid',
                $asin
            )->update(['images_data' => json_encode($vars_array['colorImages']['initial'])]);

            //print_r($vars_array['colorImages']['initial']);

            return $vars_array['colorImages']['initial'];
        } else {
            echo $link;
            print_r($vars);
            print_r($data);

            return false;
        }

    }


    public static function findVar($var, $data)
    {

        $data = substr($data, strpos($data, 'ImageBlockATF'));

        if (strpos($data, $var) == false) {
            return false;
        }
        $len = strlen($var) + 2;
        $start = strpos($data, $var." = {") + $len;
        $stop = strpos($data, "};", $start);
        $val = substr($data, $start, ($stop - $start));

        return trim($val);
    }

    public static function proxy_get($url = '')
    {

        if (!$url) {
            $url = $_GET['url'];
        }

        $n = 0;
        while ($n < 5) {

            $s = curl_init();
            curl_setopt($s, CURLOPT_URL, $url);
            curl_setopt($s, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($s, CURLOPT_TIMEOUT, 30);
            curl_setopt($s, CURLOPT_MAXREDIRS, 10);
            curl_setopt($s, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($s, CURLOPT_PROXY, '127.0.0.1:9050');
            curl_setopt($s, CURLOPT_PROXYTYPE, '7');
            curl_setopt($s, CURLOPT_USERAGENT, self::$ua[array_rand(self::$ua)]);
            curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($s, CURLOPT_VERBOSE, false);
            curl_setopt($s, CURLOPT_HEADER, true);
            $response = curl_exec($s);

            $header_size = curl_getinfo($s, CURLINFO_HEADER_SIZE);
            $header_raw = substr($response, 0, $header_size);

            $header_line = explode("\n", trim($header_raw));
            foreach ($header_line as $k => $v) {
                $header_item = explode(':', $v);
                if (count($header_item) == 2) {
                    $header[trim($header_item[0])] = $header_item[1];
                } else {
                    $header[] = $header_item[0];
                }
            }
            $body = substr($response, $header_size);
            if (stripos($body, 'Robot Check')) {
                echo "RESTART TOR";
                exec('/sbin/service tor restart');
                //exit;
            } else {
                curl_close($s);

                return ['body' => $body, 'header' => $header];
            }

            $n++;
        }

        echo "RESTART TOR";

        return false;

    }


}