<?php

namespace App\Controllers;

use App\Models\ResourceModel;
use App\Services\ResourceService;
use App\Services\ResourceBookingGuard;
use App\Services\ResourceCatalog;
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
        return view('resources/archive', ['title' => 'Archive resource', 'admin' => true, 'resource' => $this->find($id)]);
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
        foreach (['q', 'resource_type', 'category', 'status'] as $field) {
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
                ->orLike('location', $filters['q'])->orLike('catalog_group', $filters['q']);
            if ($admin) {
                $model->orLike('inventory_identifier', $filters['q']);
            }
            $model->groupEnd();
        }
        foreach (['resource_type', 'category', 'status'] as $field) {
            if ($filters[$field] !== '') {
                $model->where($field, $filters[$field]);
            }
        }
        if ($admin) $model->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        else $model->orderBy('name', 'ASC')->orderBy('id', 'ASC');
        if ($admin) {
            $resources = $model->paginate(10);
            $pager = $model->pager;
        } else {
            $allResources = ResourceCatalog::borrowerGroups($model->findAll());
            $page = max(1, (int) ($this->request->getGet('page') ?? 1));
            $pager = service('pager');
            $pager->makeLinks($page, 10, count($allResources));
            $resources = array_slice($allResources, ($page - 1) * 10, 10);
        }
        return view($admin ? 'resources/admin_index' : 'resources/index', [
            'title' => $admin ? 'Resource Management' : 'Resource catalog',
            'admin' => $admin, 'resources' => $resources, 'filters' => $filters, 'pager' => $pager,
        ]);
    }

    private function details(int $id, bool $admin): string
    {
        $resource = $this->find($id);
        if (!$admin && $resource['resource_type'] === 'equipment' && !empty($resource['catalog_group'])) {
            $members = (new ResourceModel())->where('resource_type', 'equipment')
                ->where('catalog_group', $resource['catalog_group'])->where('category', $resource['category'])
                ->where('rate_per_hour', $resource['rate_per_hour'])->where('status', $resource['status'])->findAll();
            $resource['name'] = $resource['catalog_group'];
            $resource['quantity'] = array_sum(array_map(static fn(array $item): int => (int) $item['quantity'], $members));
            $resource['location'] = count(array_unique(array_filter(array_column($members, 'location')))) > 1
                ? 'Multiple locations' : ($resource['location'] ?: null);
            $resource['inventory_count'] = count($members);
        }
        return view($admin ? 'resources/admin_show' : 'resources/show', ['title' => $resource['name'], 'resource' => $resource, 'admin' => $admin]);
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
        $resource ??= [
            'resource_type' => 'facility', 'category' => 'Facility', 'quantity' => '1',
            'rate_per_hour' => '', 'status' => 'available', 'catalog_group' => '',
            'inventory_identifier' => '', 'conflict_group' => null, 'conflict_group_alt' => null,
        ];
        $resource['conflict_groups'] = implode(', ', array_filter([
            $resource['conflict_group'] ?? null,
            $resource['conflict_group_alt'] ?? null,
        ]));
        return view('resources/form', [
            'title' => isset($resource['id']) ? 'Edit resource' : 'Create resource', 'admin' => true,
            'resource' => $resource, 'errors' => $errors,
            'pricing' => config('ResourcePricing'),
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
        if ($existing) {
            try {
                (new ResourceBookingGuard())->change((int) $existing['id'], $data);
                $saved = true;
            } catch (DomainException $exception) {
                return $this->response->setStatusCode(422)->setBody($this->form($data + ['id' => $existing['id']], ['booking' => $exception->getMessage()]));
            }
        } else {
            try {
                $saved = (new ResourceBookingGuard())->create($data);
            } catch (DomainException $exception) {
                return $this->response->setStatusCode(422)->setBody($this->form($data, ['resource' => $exception->getMessage()]));
            }
        }
        if ($saved === false) {
            return $this->response->setStatusCode(503)->setBody('The resource could not be saved. Please try again later.');
        }
        $id = $existing['id'] ?? $saved;
        return redirect()->to(site_url('admin/resources/' . $id))->with('success', 'Resource saved.');
    }
}
