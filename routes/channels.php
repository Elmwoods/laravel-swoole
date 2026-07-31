<?php

use Illuminate\Support\Facades\Broadcast;

// 广播频道授权：定义私有频道的准入回调（用于 WebSocket / Reverb 实时推送）。
// App.Models.User.{id} 是每个用户的私有频道；仅当登录用户的 id 与频道 id 一致时才授权订阅，
// 防止用户监听他人的私有事件。
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
