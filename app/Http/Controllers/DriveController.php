<?php
/**
 * This file is part of the wangningkai/olaindex.
 * (c) wangningkai <i@ningkai.wang>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace App\Http\Controllers;

use App\Models\Account;
use App\Service\GraphErrorEnum;
use Illuminate\Http\Request;
use App\Helpers\HashidsHelper;
use App\Helpers\Tool;
use Cache;
use Cookie;

class DriveController extends BaseController
{
    /**
     * 资源处理
     * @param Request $request
     * @param $hash
     * @param string $query
     * @return mixed
     * @throws \Exception
     */
    public function query(Request $request, $hash = '', $query = '')
    {
        $view = '';
        $accounts = Account::fetchlist();
        if (!$hash) {
            $account_id = setting('primary_account', 0);
            $hash = HashidsHelper::encode($account_id);
        } else {
            $account_id = HashidsHelper::decode($hash);
        }
        if (!$account_id) {
            abort(404, '尚未设置账号！');
        }
        $account = Account::find($account_id);
        if (!$account) {
            abort(404, '账号不存在！');
        }
        // 资源处理
        $config = $account->config;
        $root = $account->config['root'] ?? '/';
        $query = trim($query, '/');
        $path = explode('/', $query);
        $path = array_filter($path);
        $query = trans_absolute_path(trim("{$root}/$query", '/'));

        $service = $account->getOneDriveService();
        // 缓存处理
        $item = Cache::remember("d:item:{$account_id}:{$query}", setting('cache_expires'), static function () use ($service, $query) {
            return $service->fetchItem($query);
        });
        if (array_key_exists('code', $item)) {
            $msg = array_get($item, 'message', '404NotFound');
            $msg = GraphErrorEnum::get($item['code']) ?? $msg;
            Cache::forget("d:item:{$account_id}:{$query}");
            abort(500, $msg);
        }
        // todo:处理加密
        /*$store_encrypt_key = "e:{$hash}";
        $encrypt_path = setting($store_encrypt_key, []);
        $need_pass = false;
        if (array_key_exists($item['id'], $encrypt_path)) {
            $password = array_get($encrypt_path, $item['id']);
            if (Cookie::has("e:{$hash}:{$item['id']}")) {
                $data = json_decode(Cookie::get("e:{$hash}:{$item['id']}"), true);
                if (strcmp($password, decrypt($data['password'])) !== 0) {
                    Cookie::forget("e:{$hash}:{$item['id']}");
                    $this->showMessage('密码已过期', true);
                    $need_pass = true;
                }
            } else {
                $need_pass = true;
            }
            if ($need_pass) {
                $redirect = $redirectQuery;
                return view(config('olaindex.theme') . 'password', compact('hash', 'item', 'redirect'));
            }
        }*/

        // 处理文件
        $isFile = false;
        if (array_key_exists('file', $item)) {
            $isFile = true;
        }
        if ($isFile) {
            $item = $this->filterItem($item, $hash);
            $file = $this->formatItem($item, true);
            $download = $file['@microsoft.graph.downloadUrl'];
            if ($request->get('download')) {
                return redirect()->away($download);
            }
            $file['download'] = $download;
            $showList = [
                'stream' => explode(' ', setting('show_stream')),
                'image' => explode(' ', setting('show_image')),
                'video' => explode(' ', setting('show_video')),
                'dash' => explode(' ', setting('show_dash')),
                'audio' => explode(' ', setting('show_audio')),
                'code' => explode(' ', setting('show_code')),
                'doc' => explode(' ', setting('show_doc')),
            ];
            foreach ($showList as $key => $suffix) {
                if (in_array($file['ext'] ?? '', $suffix, false)) {
                    $show = $key;
                    // 处理文本
                    if (in_array($key, ['stream', 'code'])) {
                        // 文件>5m 无法预览
                        if ($file['size'] > 5 * 1024 * 1024) {
                            $this->showMessage('文件过大，请下载查看', false);

                            return redirect()->back();
                        }
                        try {
                            $content = Cache::remember("d:content:{$account_id}:{$file['id']}", setting('cache_expires'), static function () use ($download) {
                                return Tool::fetchContent($download);
                            });
                        } catch (\Exception $e) {
                            $this->showMessage($e->getMessage(), true);
                            Cache::forget("d:content:{$account_id}:{$file['id']}");
                            $content = '';
                        }

                        $file['content'] = $content;
                        if ($key === 'stream') {
                            $show = 'code';
                        }
                    }
                    // 处理缩略图
                    if (in_array($key, ['image', 'dash', 'video'])) {
                        $thumb = array_get($file, 'thumbnails.0.large.url');
                        $file['thumb'] = $thumb;
                    }
                    // dash视频流
                    if ($key === 'dash') {
                        if (!strpos($download, 'sharepoint.com')) {
                            return redirect()->away($download);
                        }
                        $replace = str_replace('thumbnail', 'videomanifest', $file['thumb']);
                        $dash = $replace . '&part=index&format=dash&useScf=True&pretranscode=0&transcodeahead=0';
                        $file['dash'] = $dash;
                    }
                    // 处理微软文档
                    if ($key === 'doc') {
                        $url = 'https://view.officeapps.live.com/op/view.aspx?src='
                            . urlencode($download);

                        return redirect()->away($url);
                    }
                    return view(config('olaindex.theme') . 'preview' . $view, compact('accounts', 'hash', 'path', 'show', 'file'));
                }
            }
            return redirect()->away($download);
        }

        $list = Cache::remember("d:list:{$account_id}:{$query}", setting('cache_expires'), static function () use ($service, $query) {
            return $service->fetchList($query);
        });
        if (array_key_exists('code', $list)) {
            $msg = array_get($list, 'message', '404NotFound');
            $msg = GraphErrorEnum::get($list['code']) ?? $msg;
            Cache::forget("d:list:{$account_id}:{$query}");
            abort(500, $msg);
        }
        // 处理列表
        $doc = $this->filterDoc($account_id, $list);
        // 资源过滤
        $list = $this->filter($list, $hash);
        // 资源处理
        $list = $this->formatItem($list);
        // 资源排序
        $sortBy = $request->get('sortBy', 'name');
        $direction = 'desc';
        $column = 'name';
        if (str_contains($sortBy, ',')) {
            [$column, $direction] = explode(',', $sortBy);
        }
        $descending = $direction === 'desc';
        $list = $this->sort($list, $column, $descending);
        // 分页
        $perPage = array_get($config, 'list_limit', 10);
        $list = $this->paginate($list, $perPage, false);

        return view(config('olaindex.theme') . 'one' . $view, compact('accounts', 'hash', 'path', 'item', 'list', 'doc'));
    }

    /**
     * 解密资源
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function decrypt(Request $request)
    {
        $input_password = $request->get('password');
        $redirect = $request->get('redirect');
        $hash = $request->get('hash');
        $query = $request->get('query');
        $data = [
            'password' => encrypt($input_password),
            'hash' => $hash,
            'query' => $query,
        ];
        $data = json_encode($data);
        // 处理加密
        $store_encrypt_key = "e:{$hash}";
        $encrypt_path = setting($store_encrypt_key, []);
        if (array_key_exists($query, $encrypt_path)) {
            $password = array_get($encrypt_path, $query);
            if (strcmp($password, $input_password) === 0) {
                return redirect()->route('drive.query', ['hash' => $hash, 'query' => $redirect])->withCookie("e:{$hash}:{$query}", $data, 600);
            }
        }
        return redirect()->back();
    }

    /**
     * 过滤
     * @param array $list
     * @param string $hash
     * @return \Illuminate\Support\LazyCollection
     */
    private function filter($list = [], $hash = '')
    {
        $collect = collect($list)->lazy();
        // 过滤微软内置无法读取的文件 & 过滤预留文件
        $data = $collect->filter(static function ($value) {
            return !array_has($value, 'package.type')
                || !in_array($value['name'], ['.password', '.deny', 'README.md', 'HEAD.md',], false);
        });
        // todo:过滤隐藏文件
        /*$store_hide_key = "h:{$hash}";
        $hidden_path = setting($store_hide_key, []);
        $list = array_where($list, static function ($value) use ($hidden_path) {
            return !in_array($value['id'], $hidden_path, false);
        });*/
        return $data;
    }

    /**
     * 获取说明文件
     * @param $account_id
     * @param array $list
     * @return array
     */
    private function filterDoc($account_id, $list = [])
    {
        $collect = collect($list)->lazy();

        $readme = $collect->filter(static function ($value) {
            return $value['name'] === 'README.md';
        });
        $head = $collect->filter(static function ($value) {
            return $value['name'] === 'HEAD.md';
        });

        if ($readme->isNotEmpty()) {
            $readme = $readme->first();
            try {
                $readme = Cache::remember("d:content:{$account_id}:{$readme['id']}", setting('cache_expires'), static function () use ($readme) {
                    return Tool::fetchContent($readme['@microsoft.graph.downloadUrl']);
                });
            } catch (\Exception $e) {
                $this->showMessage($e->getMessage(), true);
                Cache::forget("d:content:{$account_id}:{$readme['id']}");
                $readme = '';
            }
        } else {
            $readme = '';
        }
        if ($head->isNotEmpty()) {
            $head = $head->first();
            try {
                $head = Cache::remember("d:content:{$account_id}:{$head['id']}", setting('cache_expires'), static function () use ($head) {
                    return Tool::fetchContent($head['@microsoft.graph.downloadUrl']);
                });
            } catch (\Exception $e) {
                $this->showMessage($e->getMessage(), true);
                Cache::forget("d:content:{$account_id}:{$head['id']}");
                $head = '';
            }
        } else {
            $head = '';
        }


        return compact('head', 'readme');
    }

    /**
     * 过滤非法预览
     * @param array $item
     * @param string $hash
     * @return mixed
     */
    private function filterItem($item, $hash)
    {
        $illegalFile = ['README.md', 'HEAD.md', '.password', '.deny'];
        $pattern = '/^README\.md|HEAD\.md|\.password|\.deny/';
        if (in_array($item['name'], $illegalFile, false) || preg_match($pattern, $item['name'], $arr) > 0) {
            abort(403, '非法请求');
        }
        // 处理隐藏文件
        $store_hide_key = "h:{$hash}";
        $hidden_path = setting($store_hide_key, []);
        if (in_array($item['id'], $hidden_path, false)) {
            abort(404, '文件不存在');
        }

        return $item;
    }

    /**
     * 排序(支持 name\size\lastModifiedDateTime)
     * @param array $list
     * @param string $field
     * @param bool $descending
     * @return array
     */
    private function sort($list = [], $field = 'name', $descending = false)
    {
        $collect = collect($list)->lazy();
        // 筛选文件夹/文件夹
        $folders = $collect->filter(static function ($value) {
            return array_has($value, 'folder');
        });
        $files = $collect->filter(static function ($value) {
            return !array_has($value, 'folder');
        });
        // 执行文件夹/文件夹 排序
        if (!$descending) {
            $folders = $folders->sortBy($field, $field === 'name' ? SORT_NATURAL : SORT_REGULAR)->all();
            $files = $files->sortBy($field, $field === 'name' ? SORT_NATURAL : SORT_REGULAR)->all();
        } else {
            $folders = $folders->sortByDesc($field, $field === 'name' ? SORT_NATURAL : SORT_REGULAR)->all();
            $files = $files->sortByDesc($field, $field === 'name' ? SORT_NATURAL : SORT_REGULAR)->all();
        }
        return collect($folders)->merge($files)->all();
    }

    /**
     * 格式化
     * @param $data
     * @param bool $isFile
     * @return array
     */
    private function formatItem($data = [], $isFile = false)
    {
        if ($isFile) {
            $data['ext'] = strtolower(
                pathinfo(
                    $data['name'],
                    PATHINFO_EXTENSION
                )
            );
            return $data;
        }
        $collection = collect($data)->lazy();
        return $collection->map(static function ($item) {
            if (array_has($item, 'file')) {
                $item['ext'] = strtolower(
                    pathinfo(
                        $item['name'],
                        PATHINFO_EXTENSION
                    )
                );
            } else {
                $item['ext'] = 'folder';
            }
            return $item;
        })->all();
    }
}