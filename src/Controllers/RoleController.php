<?php

namespace QuarkCMS\QuarkAdmin\Controllers;

use Illuminate\Http\Request;
use App\Services\Helper;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Menu;
use Quark;
use DB;

class RoleController extends QuarkController
{
    public $title = '角色';

    /**
     * 列表页面
     *
     * @param  Request  $request
     * @return Response
     */
    protected function table()
    {
        $grid = Quark::grid(new Role)->title($this->title);
        $grid->column('name','名称');
        $grid->column('guard_name','guard名称');
        $grid->column('created_at','创建时间');

        $grid->column('actions','操作')->width(100)->rowActions(function($rowAction) {
            $rowAction->menu('delete', '删除')->model(function($model) {
                $model->delete();
            })->withConfirm('确认要删除吗？','删除后数据将无法恢复，请谨慎操作！');
        });

        // 头部操作
        $grid->actions(function($action) {
            $action->button('create', '创建');
            $action->button('refresh', '刷新');
        });

        // select样式的批量操作
        $grid->batchActions(function($batch) {
            $batch->option('', '批量操作');
            $batch->option('delete', '删除')->model(function($model) {
                $model->delete();
            })->withConfirm('确认要删除吗？','删除后数据将无法恢复，请谨慎操作！');
        })->style('select',['width'=>120]);

        $grid->search(function($search) {
            $search->where('name', '搜索内容',function ($query) {
                $query->where('name', 'like', "%{input}%");
            })->placeholder('名称');
        })->expand(false);

        $grid->disableAdvancedSearch();

        $grid->model()->paginate(10);

        return $grid;
    }

    /**
     * 表单页面
     * 
     * @param  Request  $request
     * @return Response
     */
    protected function form()
    {
        $form = Quark::form(new Role);

        $title = $form->isCreating() ? '创建'.$this->title : '编辑'.$this->title;
        $form->title($title);
        
        $form->id('id','ID');

        $form->text('name','名称')
        ->rules(['required','max:20'],['required'=>'名称必须填写','max'=>'名称不能超过20个字符'])
        ->creationRules(["unique:roles"],['unique'=>'名称已经存在'])
        ->updateRules(["unique:roles,name,{{id}}"],['unique'=>'名称已经存在']);

        // 查询列表
        $menus = Menu::where('status',1)->where('guard_name','admin')->select('name as title','id as key','pid')->get()->toArray();

        $menus = Helper::listToTree($menus,'key','pid','children',0);

        $form->tree('menu_ids','权限')
        ->rules(['required'],['required'=>'必须选择权限'])
        ->data($menus);

        //保存前回调
        $form->setAction(url('api/admin/role/store'));

        return $form;
    }

    /**
     * 保存方法
     * 
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $name          =   $request->json('name','');
        $menuIds       =   $request->json('menuIds');
        
        if (empty($name)) {
            return $this->error('角色名称必须填写！');
        }

        $data['name'] = $name;
        $data['guard_name'] = 'admin';

        // 添加角色
        $role = Role::create($data);

        // 根据菜单id获取所有权限
        $permissions = Permission::whereIn('menu_id',$menuIds)->pluck('id')->toArray();

        // 同步权限
        $result = $role->syncPermissions(array_filter(array_unique($permissions)));

        if ($result) {
            return $this->success('操作成功！','/admin/role/index');
        } else {
            return $this->error('操作失败！');
        }
    }

    /**
     * 编辑页面
     *
     * @param  Request  $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->get('id');

        if(empty($id)) {
            return $this->error('参数错误！');
        }

        // 所有菜单
        $menus = Menu::where('status',1)->where('guard_name','admin')->select('name as title','id as key','pid')->get()->toArray();

        $data = Role::find($id);

        $checkedMenus = [];

        foreach ($menus as $key => $menu) {
            $permissionIds = Permission::where('menu_id',$menu['key'])->pluck('id');

            $roleHasPermission = DB::table('role_has_permissions')
            ->whereIn('permission_id',$permissionIds)
            ->where('role_id',$data['id'])
            ->first();

            if($roleHasPermission) {
                $checkedMenus[] = strval($menu['key']);
            }

            $menus[$key]['key'] = strval($menu['key']);
        }

        $data['menuIds'] = $checkedMenus;

        $data = $this->form($data);

        if(!empty($data)) {
            return $this->success('操作成功！','',$data);
        } else {
            return $this->error('操作失败！');
        }
    }

    /**
     * 保存编辑数据
     *
     * @param  Request  $request
     * @return Response
     */
    public function save(Request $request)
    {
        $id            =   $request->json('id','');
        $name          =   $request->json('name','');
        $menuIds       =   $request->json('menuIds');
        
        if (empty($id)) {
            return $this->error('参数错误！');
        }

        if (empty($name)) {
            return $this->error('角色名称必须填写！');
        }

        $data['name'] = $name;
        $data['guard_name'] = 'admin';

        // 更新角色
        $result = Role::where('id',$id)->update($data);

        // 根据菜单id获取所有权限
        $permissions = Permission::whereIn('menu_id',$menuIds)->pluck('id')->toArray();

        // 同步权限
        $result1 = Role::where('id',$id)->first()->syncPermissions(array_filter(array_unique($permissions)));

        if ($result && $result1) {
            return $this->success('操作成功！','/admin/role/index');
        } else {
            return $this->error('操作失败！');
        }
    }

    /**
     * 删除单个数据
     *
     * @param  Request  $request
     * @return Response
     */
    public function changeStatus(Request $request)
    {
        $id = $request->json('id');

        if(empty($id)) {
            return $this->error('参数错误！');
        }

        $result = Role::destroy($id);

        if ($result) {
            return $this->success('操作成功！');
        } else {
            return $this->error('操作失败！');
        }
    }

}
