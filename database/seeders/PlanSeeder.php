<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\StudioSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the support plans and the studio's own defaults.
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'plan_01',
                'name' => 'Essential',
                'blurb' => 'Keeping the lights on.',
                'price' => 75,
                'hours_per_month' => 0,
                'response_time' => 'next working day',
                'features' => [
                    'Hosting, backups and SSL',
                    'Security and plugin updates',
                    'Uptime monitoring',
                    'Email support',
                ],
                'is_featured' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => 'plan_02',
                'name' => 'Care & Support',
                'blurb' => 'For a site or app you rely on.',
                'price' => 180,
                'hours_per_month' => 6,
                'response_time' => 'within 1 working day',
                'features' => [
                    'Everything in Essential',
                    '6 hours of changes a month',
                    'Phone and WhatsApp support',
                    'Quarterly review',
                ],
                'is_featured' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'plan_03',
                'name' => 'Partner',
                'blurb' => 'Your tech team, on retainer.',
                'price' => 540,
                'hours_per_month' => 20,
                'response_time' => 'same working day',
                'features' => [
                    'Everything in Care & Support',
                    'Booked capacity every sprint',
                    'Roadmap planning',
                    'Training for new staff',
                ],
                'is_featured' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        StudioSetting::current()->update([
            'company_name' => 'RSC Media Ltd',
            'company_number' => 'SC512347',
            'address' => "Unit 4, Bridgend Works\nPerth Road\nDunkeld\nPH8 0AA",
            'email' => 'info@rscmedia.co.uk',
            'phone' => '07522 375848',
            'website' => 'rscmedia.co.uk',
            'hour_rate' => 65,
            'day_rate' => 460,
            'day_length' => 7.5,
            'minimum_charge' => 0.5,
            'out_of_hours_uplift' => 50,
            'payment_terms_days' => 21,
            'late_fee_percent' => 2,
            'vat_registered' => true,
            'vat_number' => 'GB412 8873 09',
            'vat_rate' => 20,
            'account_name' => 'RSC Media Ltd',
            'bank_name' => 'Starling Bank',
            'sort_code' => '60-83-71',
            'account_number' => '41028853',
            'reference_format' => 'RSC-{invoice}',
        ]);
    }
}
