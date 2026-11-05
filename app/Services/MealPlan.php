<?php

namespace App\Services;

use App\Models\User;
use App\Support\Roles;

/**
 * What a person may book and how many meals per day: their plan (intern = lunch only),
 * narrowed by their Breakfast / Lunch / Dinner ticks and widened by "several meals a day".
 * In Ramadan everyone may book suhoor, iftar or both, and lunch is closed.
 */
class MealPlan
{
    /**
     * @return array{meals: string[], max: int, ramadan: bool, plan: string, label: string, description: string}
     */
    public static function for(User $user, ?BookingRules $rules = null): array
    {
        $rules ??= BookingRules::current();
        $role = Roles::normalize($user->User_Type);
        $plan = $role === 'intern' ? 'intern' : ($role === 'responsible' ? 'responsible' : 'standard');
        $text = [
            'standard' => ['Standard', 'One meal per day — breakfast, lunch or dinner.'],
            'intern' => ['Intern', 'Lunch, once per day, until the internship ends.'],
            'responsible' => ['Responsible', 'Standard meal plan with team sandwich ordering.'],
        ][$plan];

        if ($rules->ramadan) {
            return [
                'meals' => ['breakfast', 'dinner'], 'max' => 2, 'ramadan' => true, 'plan' => $plan,
                'label' => $text[0], 'description' => 'Ramadan: suhoor, iftar, or both — every day.',
            ];
        }

        $planMeals = $plan === 'intern' ? ['lunch'] : BookingRules::MEALS;
        $ticks = ['breakfast' => $user->Breakfast, 'lunch' => $user->Lunch, 'dinner' => $user->Dinner];
        $meals = array_values(array_filter($planMeals, fn (string $m) => (int) $ticks[$m] === 1));

        if ((int) $user->multiple_meals === 1 && count($meals) > 1) {
            return [
                'meals' => $meals, 'max' => count($meals), 'ramadan' => false, 'plan' => $plan,
                'label' => $text[0], 'description' => 'Several meals a day — book any of your meals on the same day.',
            ];
        }
        return ['meals' => $meals, 'max' => 1, 'ramadan' => false, 'plan' => $plan, 'label' => $text[0], 'description' => $text[1]];
    }
}
