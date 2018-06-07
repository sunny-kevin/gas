<?php

namespace App\Http\Controllers;


class JLibController extends Controller
{
    const SENSITIVE_WORDS = [
        'search'  => ['素人娘', '盗撮', '肉奴隷', '発射', '大乱交', '2穴中出'],
        'replace' => ['素x人x娘', '盗x撮', '肉x奴x隷', '発x射', '大x乱x交', '2x穴x中x出']
    ];

    public function index()
    {
        $app = app('wechat.official_account');
        $app->server->push(function ($message) {
            if ($message['MsgType'] == 'text') {
                $content = $message['Content'];
                if (substr($content, 0, 2) == '@@') {
                    // 只查询磁链(标清)
                    $content = substr($content, 2);

                    return $this->get_magnet($content, false) ?: '请注意大写和连字符, 例如 ABS-130';
                } elseif (substr($content, 0, 1) == '@') {
                    // 只查询磁链(高清, 如果有)
                    $content = substr($content, 1);

                    return $this->get_magnet($content) ?: '请注意大写和连字符, 例如 ABS-130';
                } elseif ($content == '#') {
                    // 获取随机番号
                    return $this->rand_code_from_cache();
                } else {
                    // 查询全部信息
                    return $this->origin_query($content);
                }
            }

            return false;
        });

        return $app->server->serve();
    }


    /**
     * get_base_url
     *
     * @return string
     */
    private function get_base_url()
    {
        return sprintf(
            "%s://%s/",
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            $_SERVER['HTTP_HOST']
        );
    }

    /**
     * 传入正规拼写的番号, 返回查询到的磁链, 查不到则返回false
     * todo: rename param: $hd (prevent boolean param)
     *
     * @param string $code
     * @param bool   $hd
     * @return mixed
     */
    private function get_magnet($code, $hd = true)
    {
        // 要求输入必须严格, 例ABS-130, 反之则可能导致结果不精确
        $query_url = env('JAVBUS_BASE_URL') . $code;

        $cover_pattern = '/<a class="bigImage" href="(.+?)">/';
        $gid_pattern   = '/gid *= *(\d+)/';
        $uc_pattern    = '/uc *= *(\d+);/';
        // 以上三个正则用于匹配ajax查询jab_bus上的磁链所需要的参数

        $hd_mag_pattern     = '/href="(magnet:\?xt=urn:btih:\w{40}).*?">\s*.+\s*<a class="btn btn-mini-new btn-primary disabled"/';  // 用于匹配javbus高清搜索结果的正则
        $normal_mag_pattern = '/window\.open\(\'(magnet:\?xt=urn:btih:\w{40}).*?_self\'\)/';  // 用于匹配javbus标清搜索结果的正则

        $res = $this->unsafe_fgc($query_url);

        preg_match($gid_pattern, $res, $gid_match);
        preg_match($uc_pattern, $res, $uc_match);
        preg_match($cover_pattern, $res, $cover_match);

        $get_magnet_url = env('JAVBUS_BASE_URL') . '/ajax/uncledatoolsbyajax.php?gid=' . $gid_match[1] . '&lang=zh&img=' .
                          $cover_match[1] . '&uc=' . $uc_match[1] . '&floor=' . rand(1, 1000);

        // 伪造ajax查询所必要的headers
        $res = $this->unsafe_fgc($get_magnet_url, "Referer: " . env('JAVBUS_BASE_URL') . "\r\nCookie: existmag=mag\r\n");

        preg_match_all($hd_mag_pattern, $res, $hd_mag_match);
        preg_match_all($normal_mag_pattern, $res, $normal_mag_match);

        if ($hd) {
            // 需要高清
            if ($hd_mag_match[1]) {
                $response = $hd_mag_match[1][0];

            } else {
                if ($normal_mag_match[1]) {
                    $response = $normal_mag_match[1][0];
                } else {
                    $response = false;
                }
            }
        } else {
            // 不需要高清
            if ($normal_mag_match[1]) {
                $response = $normal_mag_match[1][0];
            } else {
                $response = false;
            }
        }

        return $response;
    }

    /**
     * 传入搜索结果唯一的番号, 需要正规拼写, 返回查询到的信息(带磁链)
     *
     * @param string $code
     * @return string | boolean
     */
    public function get_info($code)
    {
        $query_url = env('JAVBUS_BASE_URL') . $code;

        $title_pattern = '/<h3>(.+)<\/h3>/';
        $date_pattern  = '/<\/span> *(\d+-\d+-\d+) *<\/p>/';
        $cover_pattern = '/<a class="bigImage" href="(.+?)">/';
        $pic_pattern   = '/<a class="sample-box" href="(.+?)">/';

        $res = $this->unsafe_fgc($query_url);

        preg_match($title_pattern, $res, $title_match);
        preg_match($date_pattern, $res, $date_match);
        preg_match($cover_pattern, $res, $cover_match);
        preg_match_all($pic_pattern, $res, $pic_match);

        if (!isset($title_match[1])) {
            return false;
        }

        if ('0000-00-00' == $date_match[1]) {
            $date_match[1] = '未知';
        }
        $response = '车牌&车型&司机: ' . $title_match[1]
                    . "\n" . '发车日期: ' . $date_match[1];


        $response .= "\n" . '<a href="'
                     . str_replace('javbus.com', 'javcdn.pw', $cover_match[1])
                     . '">封面图</a>';


//        if ($pic_match[1]) {
//            $response .= "\n" . '<a href="' . $this->make_preview($pic_match[1], $code) . '">截图</a>';
//        }

        $magnet   = $this->get_magnet($code) ?: '找不到神秘代码';
        $response .= "\n" . $magnet;

        return str_replace(self::SENSITIVE_WORDS['search'], self::SENSITIVE_WORDS['replace'], $response);
    }

    /**
     * 传入图片url数组和番号(用作alt属性值)
     * 返回包含图片的html页面的url
     * 默认存放在preview目录下
     *
     * @param array  $picUrl
     * @param string $code
     * @param string $dirName
     * @return string
     */
    private function make_preview($picUrl, $code, $dirName = 'preview')
    {
        $path     = $_SERVER['DOCUMENT_ROOT'] . "/$dirName/";
        $filename = $code . '.html';
        if (!file_exists($path . $filename) || (filesize($path . $filename) === 0)) {
            $result = '';
            foreach ($picUrl as $value) {
                $result .= '<img src="' . $value . '" alt="' . $code . '">';
            }
            touch($path . $filename);
            file_put_contents($path . $filename, $result);
        }

        return $this->get_base_url() . "{$dirName}/{$filename}";
    }

    /**
     * 传入用户的原始输入, 返回查询到的信息(带磁链), 查不到则返回jav_lib高评价里的随机番号
     *
     * @param string $code
     * @return string
     */
    private function origin_query($code)
    {
        $search_url = env('JAVBUS_BASE_URL') . '/search/' . urlencode($code);  // 按番号搜索

        $movie_pattern = '/class="movie-box" href="(.+)">/';  // 单页影片数
        $pages_pattern = '#href="/search/\S+/(\d+)">\d+#';  // 页数

        $res = $this->unsafe_fgc($search_url);

        preg_match_all($movie_pattern, $res, $movie_match);

        if (count($movie_match[1]) == 1) {
            // 用户搜索结果唯一
            //echo $movie_match[1][0];
            $code = explode('/', $movie_match[1][0]);

            //echo end($code);  // 获取正规拼写的番号
            return $this->get_info(end($code));
        } else {
            // 用户搜索结果不唯一, 可能需要翻页处理
            preg_match_all($pages_pattern, $res, $pages_match);
            //print_r($pages_match[1]);
            $res = $this->unsafe_fgc(env('JAVBUS_BASE_URL') . '/uncensored/search/' . $code . '&type=1');
            preg_match_all($pages_pattern, $res, $unpages_match);  // 无码页数
            preg_match_all($movie_pattern, $res, $unmovie_match);
            if (count($pages_match[1]) || count($unpages_match[1])) {
                // 需要翻页
                $response = '结果太多, 尝试缩小搜索范围吧';

                return $response;
            } else {
                // 只有一页或者没找到
                //print_r(count($movie_match[1]));
                if (count($movie_match[1]) == 0) {
                    return '没有此车牌, 获取随机车牌请发送井号 (#)';
                } else {
                    // 此时两种情况, 一种是搜SW-220, 结果有DKSW-220 和 SW-220, 另一种则是寻常的模糊搜索
                    if (in_array(env('JAVBUS_BASE_URL') . $code, $movie_match[1]) || in_array(env('JAVBUS_BASE_URL') . $code, $unmovie_match[1])) {
                        // 此时便是搜SW-220, 结果有DKSW-220 和 SW-220 的情况, 需要返回SW-220的信息
                        $response = $this->get_info($code);
                    } else {
                        // 此时是正常的模糊查询
                        $response = "你是不是要找:";
                        sort($movie_match[1]);  // 按照号码大小排序, 默认顺序是按照影片热门度排序的
                        foreach ($movie_match[1] as $v) {
                            $arr      = explode('/', $v);
                            $response = $response . "\n" . $arr[3];
                        }
                        sort($unmovie_match[1]);  // 按照号码大小排序, 默认顺序是按照影片热门度排序的
                        foreach ($unmovie_match[1] as $v) {
                            $arr      = explode('/', $v);
                            $response = $response . "\n" . $arr[3];
                        }
                    }

                    return $response;
                }
            }
        }
    }

    /**
     * 通过jav_library的高评价页面随机获取番号, 只获取前十页
     * 用于用户输入非法时返回给用户
     *
     * @return string
     */
    private function randCode()
    {
        $best_rated = 'http://www.javlibrary.com/tw/vl_bestrated.php?list&mode=&page=' . rand(1, 10);

        $code_pattern = '/<a href="\.\/\?v=.+?" title="(\S+) {1}/';

        $res = $this->unsafe_fgc($best_rated);

        preg_match_all($code_pattern, $res, $code_match);

        //print_r($code_match);
        $response = $code_match[1][rand(0, count($code_match[1]))];
        $response = empty($response) ? $this->randCode() : $response;  // 防止返回空

        return $response;
    }

    private function rand_code_from_cache()
    {
        $a = (array) cache()->get('best_rated');
        return $a[mt_rand(0, count($a) - 1)];
    }

    /**
     * file_get_contents without Secure Sockets Layer(SSL)
     *
     * @param string $url
     * @param null   $header
     * @return bool|string
     */
    private function unsafe_fgc($url, $header = null)
    {
        return file_get_contents(
            $url,
            false,
            stream_context_create([
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                'http' => ['header' => $header, 'ignore_errors' => true]
            ])
        );
    }

    private function unsafe_curl($url, $header = null)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER   => false,
            CURLOPT_SSL_VERIFYHOST   => false,
            CURLOPT_SSL_VERIFYSTATUS => false,
            CURLOPT_RETURNTRANSFER   => true
        ]);
        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, explode("\r\n", $header));
        }

        return curl_exec($ch);
    }
}
