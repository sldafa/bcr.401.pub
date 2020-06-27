<?php
namespace App\Modules\Plugins;

use App\Models\Image;
use App\Modules\CQHttp\CQCode;
use App\Modules\CQHttp\CQHttp;
use App\Modules\CQHttp\Events\CQEvent;
use App\Modules\CQHttp\Events\CQMessageEvent;
use App\Modules\CQHttp\Events\CQPrivateMessageEvent;
use App\Modules\CQHttp\Events\IMessageEvent;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use PHPHtmlParser\Dom;

class HPicPlugin extends OnMessagePlugin
{
    protected $commands = [
        '涩图存量$' => 'count',
        '涩图分类$' => 'category',
        '涩图备注 (\d+) (.+)' => 'comment',
        '覆盖涩图(.*)' => 'replace',
        '删除涩图 (\d+)' => 'delete',
        '涩图 (.+)$' => 'search',
        '涩图分类 (.+)$' => 'queryByCategory',
        '涩图$' => 'queryDefault',
        '上传涩图(.*)$' => 'upload',
        '备注查询(.*)' => 'queryComment',
        '涩图帮助$' => 'help',
    ];
    protected $listen = '*';

    public function help(CQEvent $e)
    {
        $e->reply(<<<EOD
帮助如下

%涩图存量
    查看涩图总量及已缓存的数量
%涩图分类 [分类，可选]
    查看涩图分类有哪些
    如果提供了分类名，那么会在指定分类随机返回涩图
%涩图 [搜索关键词]
    来一份已缓存的随机涩图（或者按关键词搜索）
%涩图 id
    按id查看涩图
%涩图备注 id 备注内容
    给涩图写备注方便搜索
%覆盖涩图 id 图片
    使用上传的图片覆盖 id
%删除涩图 id
    删除指定涩图
%涩图搜索 关键词
    搜索备注含有关键词的涩图
%备注查询 [页码,可选,默认为1]
    统计所有已经添加备注的涩图
%上传涩图 [备注,可选] [图片/url]
    上传指定图片或url到指定分类的涩图库，默认分类为 uploaded"
EOD);
    }

    public function delete(CQEvent $e, $id)
    {
        $img = Image::find($id);
        if (empty($img)) {
            return $e->reply("未找到 id 为 $id 的涩图");
        }
        $img->delete();
        return $e->reply("成功删除涩图 $id");
    }

    public function comment(CQEvent $e, $id, $comment)
    {
        $img = Image::find($id);
        if (empty($img)) {
            return $e->reply("未找到 id 为 $id 的涩图");
        }
        $img->comment = $comment;
        $img->save();
        return $e->reply("成功设置涩图 $id 的备注为: $comment");
    }

    public function search(CQEvent $e, $comment)
    {
        $comment = trim($comment);
        if (is_numeric($comment)) {
            return $this->queryById($e, $comment);
        }

        $result = Image::where('comment', 'like', "%$comment%")->get();
        if ($result->isEmpty()) {
            return $e->reply('未找到符合的涩图');
        }
        $e->reply('找到 '.$result->count().' 张符合的涩图:');
        if ($result->count() <= 2) {
            foreach ($result as $item) {
                $e->reply("\nid: {$item->id}\n备注: {$item->comment}\n".CQCode::image($this->getUrlOrDownload($item)));
            }
        } else {
            $i = 1;
            $cnt = $result->count();
            foreach ($result as $item) {
                $e->reply("\n[$i/$cnt] id: {$item->id}, 备注: {$item->comment}");
                $i++;
            }
        }
    }

    public function count(CQEvent $e)
    {
        $data = Image::select(DB::raw('count(*) as cnt'), 'downloaded')->groupBy('downloaded')->get();
        $e->reply('涩图存量共 '. $data->sum('cnt') ." 份，其中");
        foreach ($data as $downloadGrouped) {
            if ($downloadGrouped->downloaded) {
                $e->reply("\n已下载 {$downloadGrouped->cnt} 份");
            } else {
                $e->reply("\n未下载 {$downloadGrouped->cnt} 份");
            }
        }
    }

    public function category(CQEvent $e)
    {
        $data = Image::select(DB::raw('count(*) as cnt'), 'category')->groupBy('category')->get();
        $e->reply('涩图分类存量如下：');
        foreach ($data as $category) {
            $e->reply("\n{$category->category}: $category->cnt");
        }
    }

    public function queryByCategory(CQEvent $e, $category)
    {
        if (is_numeric($category)) {
            return $this->queryById($e, $category);
        }

        $data = Image::where('downloaded', true);
        // $data = Image::whereRaw('true');
        if ($category != 'all') {
            $data = $data->where('category', $category);
        }
        $data = $data->get();
        if ($data->isEmpty()) {
            $e->reply("类别 `$category` 的已缓存涩图存量为 0");
        } else {
            $item = $data->random();
            $this->sendPicByApi($e, $item);
        }
    }

    public function queryDefault(CQEvent $e)
    {
        $this->queryByCategory($e, 'all');
    }

    public function queryComment(CQMessageEvent $e, $page)
    {
        $page = trim($page);
        if (empty($page)) {
            $page = 1;
        }
        if (!is_numeric($page)) {
            return $e->reply('页码必须为数字');
        }
        $e->reply("涩图备注统计，毎页显示 5 条：\n当前显示第 $page 页:\n");
        $data = Image::select('id', 'comment')->whereNotNull('comment')->skip(($page - 1) * 5)->limit(5)->get();
        if ($data->isEmpty()) {
            return $e->reply('本页没有记录了');
        }
        foreach ($data as $item) {
            $e->reply("\n{$item->id}: {$item->comment}");
        }
    }

    public function queryById(CQMessageEvent $e, $id)
    {
        if (!is_numeric($id)) {
            return $e->reply('请输入数字 id');
        }
        $item = Image::find($id);
        if (empty($item)) {
            $data = Image::where('id', '>', $id)->first();
            if (empty($data))
                return $e->reply("涩图 id: $id 不存在");
            $before = "涩图 id: $id 不存在，已经自动显示下一张涩图\n";
            $item = $data;
        }

        $this->sendPicByApi($e, $item, $before);
    }

    public function replace(CQMessageEvent $e, $text)
    {
        $text = trim($text);
        $id = trim(substr($text, 0, strpos($text, '[')));
        if (!is_numeric($id)) {
            return $e->reply("id `$id` 不是有效的数字 id");
        }

        $last = Image::select('id')->orderBy('id', 'desc')->limit(1)->first();
        if (empty($last)) {
            return $e->reply("数据库查询失败，可能是还没有数据");
        }
        if ($id > $last or $id <= 0) {
            return $e->reply("id 不合法，合法的 id 范围为 1-$last");
        }

        if (preg_match('/\[CQ:image,file=([^\],]*),{0,1}.*\]/', $text, $matches)) {
            $path = $matches[1];
            $img = Image::updateOrCreate(['id' => $id], [
                'id' => $id,
                'category' => 'uploaded',
                'local_path' => $path,
                'downloaded' => true,
                'extra' => [
                    'sender' => $e->getSenderId(),
                ],
            ]);
            return $e->reply("涩图覆盖成功！id: {$img->id}");
        } else {
            return $e->reply('未发现待上传涩图');
        }
        
    }

    public function upload(CQMessageEvent $e, $text)
    {
        $text = trim($text);
        $comment = trim(substr($text, 0, strpos($text, '[')));

        if (preg_match('/\[CQ:image,file=([^\],]*),{0,1}.*\]/', $text, $matches)) {
            $path = $matches[1];
            $item = Image::create([
                'category' => 'uploaded',
                'local_path' => $path,
                'downloaded' => true,
                'comment' => empty($comment) ? null : $comment,
                'extra' => [
                    'sender' => $e->getSenderId(),
                ],
            ]);
            $e->reply("上传成功！id: $item->id");
        } else if (preg_match('/(http[^\s]+)/', $text, $matches)) {
            $url = $matches[1];
            $item = Image::create([
                'category' => 'uploaded',
                'origin_url' => $url,
                'downloaded' => false,
                'comment' => empty($comment) ? null : $comment,
                'extra' => [
                    'sender' => $e->getSenderId(),
                ],
            ]);
            $e->reply("上传成功！id: $item->id");
        } else {
            $e->reply('未发现待上传涩图');
        }
    }

    /*******************************/
    protected function getUrlOrDownload(Image $item)
    {
        if ($item->local_path) {
            return $item->local_path;
        } else {
            $data = Image::download($item->origin_url);
            if (empty($data)) {
                return $item->origin_url;
            } else {
                $item->local_path = $data['local_path'];
                $item->downloaded = 1;
                $item->save();
                return $item->local_path;
            }
        }
    }

    protected function sendPicByApi(CQMessageEvent $e, Image $item, $before = '', $after = '')
    {
        $cq = CQHttp::instance();
        $url = $this->getUrlOrDownload($item);
        if (empty($item->local_path)) {
            $before = $before."\n[互联网图片] 下载失败，原始 url:\n {$item->origin_url}";
        }
        $message = $before."id: {$item->id}\n".CQCode::image($url).$after;
        $ret = $cq->sendMessage($e->getMessageType(), $e->getMessageSourceId(), $message);
        if ($ret != 0) {
            if ($ret == -1 || $ret == -11) {
                $cq->sendMessage($e->getMessageType(), $e->getMessageSourceId(),
                    "id: {$item->id} 图片发送超时，图片 url：\nhttps://bcr.401.pub/download/image?id={$item->id}");
            } else {
                $e->reply("Call CQHttp api failed, ret $ret");
            }
        }
    }
}
