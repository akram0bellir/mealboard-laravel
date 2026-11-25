<?php

namespace Tests\Feature;

use Tests\TestCase;

class KitchenCountsTest extends TestCase
{
    public function test_kitchen_counts_are_public_without_authentication(): void
    {
        $this->get('/kitchen')
            ->assertOk()
            ->assertSee('Kitchen counts')
            ->assertDontSee('Log out');
    }

    public function test_kitchen_counts_support_a_custom_range_meal_and_department_filter(): void
    {
        $one = $this->person(['Department' => '1']);
        $two = $this->person(['Department' => '2']);
        $this->order($one, '2038-11-04', ['lunch']);
        $this->order($one, '2038-11-05', ['breakfast', 'lunch']);
        $this->order($two, '2038-11-05', ['lunch']);

        $this->actingAs($this->admin())->get('/kitchen?from=2038-11-04&to=2038-11-05&meal=lunch&department=1')
            ->assertOk()
            ->assertSee('Monthly totals')
            ->assertSee('Daily portions')
            ->assertSee('value="1" selected', false)
            ->assertSee('value="lunch" selected', false)
            ->assertSee('2038-11-04')
            ->assertDontSee('override rules')
            ->assertSee('<td class="px-3 py-3 text-end">2</td>', false);
    }

    public function test_a_manager_can_open_the_kitchen_counts_page(): void
    {
        $this->actingAs($this->admin('manager'))->get('/kitchen')->assertOk()->assertSee('Kitchen counts')->assertDontSee('override rules');
    }

    public function test_a_custom_range_cannot_exceed_one_year(): void
    {
        $this->actingAs($this->admin())
            ->get('/kitchen?from=2038-01-01&to=2039-01-02')
            ->assertRedirect()
            ->assertSessionHasErrors('to');
    }
}
