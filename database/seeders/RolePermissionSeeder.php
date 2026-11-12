<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const ROLES = [
        'school_admin' => 'Admin Sekolah',
        'teacher' => 'Guru',
        'homeroom_teacher' => 'Wali Kelas',
        'student' => 'Siswa',
    ];

    /**
     * @var array<string, array{description: string, scope: string}>
     */
    private const PERMISSIONS = [
        'school.view' => ['description' => 'Melihat profil sekolah', 'scope' => 'tenant'],
        'school.update' => ['description' => 'Mengubah profil sekolah', 'scope' => 'tenant'],
        'members.view' => ['description' => 'Melihat anggota sekolah', 'scope' => 'tenant'],
        'members.invite' => ['description' => 'Mengundang anggota sekolah', 'scope' => 'tenant'],
        'members.update' => ['description' => 'Mengubah data membership', 'scope' => 'tenant'],
        'members.suspend' => ['description' => 'Menangguhkan membership', 'scope' => 'tenant'],
        'members.assign_roles' => ['description' => 'Mengatur role membership', 'scope' => 'tenant'],
        'members.assisted_reset' => ['description' => 'Memulai reset berbantuan single-tenant', 'scope' => 'tenant'],
        'academic.manage' => ['description' => 'Mengelola administrasi akademik', 'scope' => 'tenant'],
        'classrooms.view' => ['description' => 'Melihat rombel', 'scope' => 'tenant'],
        'classrooms.manage' => ['description' => 'Mengelola rombel', 'scope' => 'tenant'],
        'subjects.manage' => ['description' => 'Mengelola mata pelajaran', 'scope' => 'tenant'],
        'courses.manage' => ['description' => 'Mengelola course', 'scope' => 'tenant'],
        'learning.view' => ['description' => 'Melihat materi pembelajaran', 'scope' => 'tenant'],
        'learning.create' => ['description' => 'Membuat materi pembelajaran', 'scope' => 'tenant'],
        'learning.update' => ['description' => 'Mengubah materi pembelajaran', 'scope' => 'tenant'],
        'learning.publish' => ['description' => 'Menerbitkan materi pembelajaran', 'scope' => 'tenant'],
        'assignments.view' => ['description' => 'Melihat tugas', 'scope' => 'tenant'],
        'assignments.manage' => ['description' => 'Mengelola tugas', 'scope' => 'tenant'],
        'submissions.create' => ['description' => 'Mengirim tugas sendiri', 'scope' => 'tenant'],
        'submissions.view_own' => ['description' => 'Melihat pengumpulan sendiri', 'scope' => 'tenant'],
        'submissions.grade' => ['description' => 'Menilai pengumpulan', 'scope' => 'tenant'],
        'quizzes.manage' => ['description' => 'Mengelola kuis', 'scope' => 'tenant'],
        'quizzes.take' => ['description' => 'Mengerjakan kuis', 'scope' => 'tenant'],
        'quiz_results.view_own' => ['description' => 'Melihat hasil kuis sendiri', 'scope' => 'tenant'],
        'quiz_results.grade' => ['description' => 'Menilai hasil kuis', 'scope' => 'tenant'],
        'grades.view_course' => ['description' => 'Melihat nilai course', 'scope' => 'tenant'],
        'grades.view_own_published' => ['description' => 'Melihat nilai terbit sendiri', 'scope' => 'tenant'],
        'grades.publish' => ['description' => 'Menerbitkan nilai', 'scope' => 'tenant'],
        'grades.view_homeroom_summary' => ['description' => 'Melihat ringkasan nilai rombel', 'scope' => 'tenant'],
        'audit.view_tenant' => ['description' => 'Melihat audit tenant', 'scope' => 'tenant'],
        'platform.tenants.view' => ['description' => 'Melihat tenant pada portal pusat', 'scope' => 'platform'],
        'platform.tenants.create' => ['description' => 'Membuat tenant pada portal pusat', 'scope' => 'platform'],
        'platform.tenants.update' => ['description' => 'Mengubah metadata tenant', 'scope' => 'platform'],
        'platform.tenants.transition' => ['description' => 'Mengubah status tenant', 'scope' => 'platform'],
        'platform.plans.assign' => ['description' => 'Menetapkan paket tenant', 'scope' => 'platform'],
        'platform.audit.view' => ['description' => 'Melihat audit platform', 'scope' => 'platform'],
        'platform.staff.mfa_reset' => ['description' => 'Mereset MFA staf platform secara teraudit', 'scope' => 'platform'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'school_admin' => [
            'school.view', 'school.update',
            'members.view', 'members.invite', 'members.update', 'members.suspend',
            'members.assign_roles', 'members.assisted_reset',
            'academic.manage', 'classrooms.view', 'classrooms.manage', 'subjects.manage', 'courses.manage',
            'audit.view_tenant',
        ],
        'teacher' => [
            'school.view', 'learning.view', 'learning.create', 'learning.update', 'learning.publish',
            'assignments.view', 'assignments.manage', 'submissions.grade',
            'quizzes.manage', 'quiz_results.grade', 'grades.view_course', 'grades.publish',
        ],
        'homeroom_teacher' => [
            'school.view', 'classrooms.view', 'grades.view_homeroom_summary',
        ],
        'student' => [
            'school.view', 'learning.view', 'assignments.view', 'submissions.create', 'submissions.view_own',
            'quizzes.take', 'quiz_results.view_own', 'grades.view_own_published',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $roleIds = [];
            $permissionIds = [];

            foreach (self::ROLES as $key => $displayName) {
                DB::table('roles')->updateOrInsert(
                    ['key' => $key],
                    ['display_name' => $displayName, 'is_system' => true, 'updated_at' => $now, 'created_at' => $now],
                );

                $roleIds[$key] = (int) DB::table('roles')->where('key', $key)->value('id');
            }

            foreach (self::PERMISSIONS as $key => $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $key],
                    [...$permission, 'updated_at' => $now, 'created_at' => $now],
                );

                $permissionIds[$key] = (int) DB::table('permissions')->where('key', $key)->value('id');
            }

            foreach (self::ROLE_PERMISSIONS as $roleKey => $permissionKeys) {
                foreach ($permissionKeys as $permissionKey) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $roleIds[$roleKey], 'permission_id' => $permissionIds[$permissionKey]],
                        ['permission_scope' => 'tenant'],
                    );
                }
            }
        });
    }
}
