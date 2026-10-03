<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * A page the test app serves, so the redirects tests can tell "served" from "redirected" or "gone".
 */
class HealthController extends Controller
{
    /**
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        return $this->response->withType('text')->withStringBody('ok');
    }
}
