<?php

namespace App\Controllers;

use App\Models\ResourceModel;
use App\Services\ResourceService;
use App\Services\ResourceBookingGuard;
use DomainException;
use CodeIgniter\Exceptions\PageNotFoundException;

class ResourceController extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function adminIndex()
    {
        return $this->listing(true);
    }

    public function catalog()
    {
        return $this->listing(false);
    }

    public function adminShow(int $id)
    {
        return $this->details($id, true);
    }

    public function show(int $id)
    {
        return $this->details($id, false);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function store()
    {
        return $this->save(null);
    }

    public function edit(int $id)
    {
        return $this->form($this->find($id));
    }

    public function update(int $id)
    {
        return $this->save($this->find($id));
    }

    public function archiveForm(int $id)
    {
        return view('resources/archive', ['title' => 'Archive resource', 'resource' => $this->find($id)]);
    }

    public function archive(int $id)
    {
        $this->find($id);
        if ($this->request->getPost('confirm_archive') !== 'yes') {
            return redirect()->to(site_url('admin/resources/' . $id . '/archive'))
                ->with('error', 'Confirm that you want to archive this resource.');
        }
        try {
            (new ResourceBookingGuard())->change($id, null);
        } catch (DomainException $exception) {
            return redirect()->to(site_url('admin/resources/' . $id))->with('error', $exception->getMessage());
        }
        return redirect()->to(site_url('admin/resources'))->with('success', 'Resource archived. Its stored record has been preserved.');
    }

    private function listing(bool $admin): string
    {
        $model = new ResourceModel();
        $filters = [];
        foreach (['q', 'resource_type', 'status'] as $field) {
            $value = $this->request->getGet($field);
            $filters[$field] = is_string($value) ? mb_substr(trim($value), 0, 150) : '';
        }
        if (!in_array($filters['resource_type'], ['facility', 'equipment'], true)) {
            $filters['resource_type'] = '';
        }
        if (!in_array($filters['status'], ['available', 'unavailable', 'maintenance'], true)) {
            $filters['status'] = '';
        }
        if ($filters['q'] !== '') {
            $model->groupStart()->like('name', $filters['q'])->orLike('description', $filters['q'])
                ->orLike('location', $filters['q'])->groupEnd();
        }
        foreach (['resource_type', 'status'] as $field) {
            if ($filters[$field] !== '') {
                $model->where($field, $filters[$field]);
            }
        }
        $resources = $model->orderBy('name', 'ASC')->orderBy('id', 'ASC')->paginate(10);
        return view('resources/index', [
            'title' => $admin ? 'Manage resources' : 'Resource catalog',
            'admin' => $admin, 'resources' => $resources, 'filters' => $filters, 'pager' => $model->pager,
        ]);
    }

    private function details(int $id, bool $admin): string
    {
        $resource = $this->find($id);
        return view('resources/show', ['title' => $resource['name'], 'resource' => $resource, 'admin' => $admin]);
    }

    private function find(int $id): array
    {
        $resource = (new ResourceModel())->find($id);
        if (!$resource) {
            throw PageNotFoundException::forPageNotFound('Resource not found.');
        }
        return $resource;
    }

    private function form(?array $resource, array $errors = [])
    {
        return view('resources/form', [
            'title' => isset($resource['id']) ? 'Edit resource' : 'Create resource',
            'resource' => $resource ?? ['resource_type' => 'facility', 'quantity' => '1', 'rate_per_hour' => '0.00', 'status' => 'available'],
            'errors' => $errors,
        ]);
    }

    private function save(?array $existing)
    {
        [$data, $errors] = (new ResourceService())->validate($this->request->getPost());
        if ($errors !== []) {
            $resource = $data;
            if ($existing) {
                $resource['id'] = $existing['id'];
            }
            return $this->response->setStatusCode(422)->setBody($this->form($resource, $errors));
        }
        $model = new ResourceModel();
        if ($existing) {
            try {
                (new ResourceBookingGuard())->change((int) $existing['id'], $data);
                $saved = true;
            } catch (DomainException $exception) {
                return $this->response->setStatusCode(422)->setBody($this->form($data + ['id' => $existing['id']], ['booking' => $exception->getMessage()]));
            }
        } else {
            $saved = $model->insert($data);
        }
        if ($saved === false) {
            return $this->response->setStatusCode(503)->setBody('The resource could not be saved. Please try again later.');
        }
        $id = $existing['id'] ?? $saved;
        return redirect()->to(site_url('admin/resources/' . $id))->with('success', 'Resource saved.');
    }
}
