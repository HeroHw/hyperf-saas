<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Controller;

use App\Model\Admin;
use Hyperf\Context\Context;

class IndexController extends AbstractController
{
    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        Context::set('current_tenant', [
            'schema' => 'public',
        ]);
        try {
            $a = Admin::where('id', '=', 1)->firstOrFail();
        } catch (\Exception $e) {
            return [
                'message' => $e->getMessage(),
            ];
        }


        return [
            'test' => $a,
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }
}
