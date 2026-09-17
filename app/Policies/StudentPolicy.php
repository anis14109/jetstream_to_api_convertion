<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /**
     * Determine whether the user can list students.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the student.
     */
    public function view(User $user, Student $student): bool
    {
        return $student->user_id === $user->id;
    }

    /**
     * Determine whether the user can create students.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the student.
     */
    public function update(User $user, Student $student): bool
    {
        return $student->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the student.
     */
    public function delete(User $user, Student $student): bool
    {
        return $student->user_id === $user->id;
    }
}
