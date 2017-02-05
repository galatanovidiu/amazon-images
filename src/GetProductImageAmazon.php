<?php
/**
 * TOR is needed when lots of requests are made to amazon site as at some point
 * they will ask to demonstrate you are not a robot
 * so in this case TOR restart will fore change IP
 * and make a new request
 */

namespace Galatanovidiu\Amazonimage;

class GetProductImageAmazon implements GetProductImageInterface
{

    public function get_images($asin)
    {

        $link = 'http://www.amazon.com/dp/'.$asin;

        $data = self::proxy_get($link);
        $vars = self::findVar('data', $data['body']);
        $vars = preg_replace("/'(\w+)'/i", '"$1"', $vars).'}';
        $vars_array = json_decode($vars, true);

        if (is_array($vars_array)) {
            foreach ($vars_array as $k => $response) {
                if (isset($response['hiRes']) and $response['hiRes']) {
                    $image_id = md5($response['thumb']);
                    $images[$image_id] = ['URL' => isset($response['hiRes']) ? $response['hiRes'] : ''];
                }
            }
        }

        return $images;
    }


    public function findVar($var, $data)
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

    /**
     * @param string $url
     *
     * @return array|bool
     *
     * TODO: improve functionality and make make it more abstract
     * TODO: try and make it so tor can be restarted by non root user (make possible tor restart from outside root environment ) !!!???
     *
     */
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