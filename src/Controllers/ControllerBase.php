<?php

namespace App\Controllers;

use App\Database;

abstract class ControllerBase
{

    /** @var \App\User */
    protected $user;

    /** @var \App\Database */
    protected $db;

    public function __construct()
    {
        $this->db = new Database();
        /*
        if (isset($_SESSION['user_id'])) {
            $sql = 'SELECT `id`, `name` FROM users WHERE id=:id';
            $this->user = $this->db->query($sql, ['id' => $_SESSION['user_id']])->fetch();
        }
        */
    }

    protected function redirect($route)
    {
        $url = \App\Config::baseUrl() . '/' . ltrim($route, '/ ');
        http_response_code(303);
        header("Location: $url");
        exit(0);
    }

    protected function sendFile($ext, $mime, $content, $downloadName = false)
    {
        $downloadName = ($downloadName ? : date('Y-m-d') ) . '.' . $ext;
        header('Content-Encoding: UTF-8');
        header('Content-type: ' . $mime . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        echo $content;
        exit;
    }
}
