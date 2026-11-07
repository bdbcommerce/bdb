{{-- Online shop block: links to the catalog (CompanyInfo::shop_url, default mylabel.uz). Optional $placement for UTM. --}}
@php
    $__shopUrl  = $companyInfo->shopUrl($placement ?? 'shop_block');
    $__shopHost = $companyInfo->shopHost();
@endphp
@if($__shopUrl)
<section id="shop" class="bg-white" aria-labelledby="shop-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-24">
        <div class="relative overflow-hidden rounded-2xl border border-gray-200 bg-gray-50">
            <div class="absolute inset-0 bg-dots mask-fade-r pointer-events-none" aria-hidden="true"></div>
            <div class="absolute -left-24 -bottom-24 size-80 rounded-full bg-brand-600/10 blur-3xl pointer-events-none" aria-hidden="true"></div>

            <div class="relative grid gap-10 px-6 py-10 sm:px-10 md:px-14 md:py-14 lg:grid-cols-2 lg:items-center">
                {{-- Copy --}}
                <div>
                    <p class="eyebrow">{{ __('site.shop.eyebrow') }}</p>
                    <h2 id="shop-title" class="section-title">{{ __('site.shop.title', ['site' => $__shopHost]) }}</h2>
                    <p class="section-lead">{{ __('site.shop.lead') }}</p>

                    <ul class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3 text-sm text-gray-700">
                        @foreach((array) __('site.shop.points') as $i => $point)
                            <li class="flex items-start gap-2.5">
                                <span class="icon-tile !size-8 text-brand-600">
                                    @include('site.partials.icon', ['name' => ['shopping-bag', 'clock', 'chat-bubble-left-right'][$i] ?? 'check', 'class' => 'size-4'])
                                </span>
                                <span class="pt-1.5">{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8 flex flex-col sm:flex-row gap-3" x-data>
                        <a href="{{ $__shopUrl }}" target="_blank" rel="noopener" class="btn-primary">
                            @include('site.partials.icon', ['name' => 'shopping-cart', 'class' => 'size-4'])
                            {{ __('site.shop.cta') }}
                            @include('site.partials.icon', ['name' => 'arrow-up-right', 'class' => 'size-4'])
                            <span class="sr-only">{{ __('site.shop.new_tab') }}</span>
                        </a>
                        <button type="button" @click="$dispatch('belgi-open')" class="btn-secondary">
                            @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                            {{ __('site.cta.ask_ai') }}
                        </button>
                    </div>
                </div>

                {{-- Browser-window mock of the shop: the whole card is a link to the catalog --}}
                <a href="{{ $__shopUrl }}" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true"
                   class="group block rounded-xl border border-gray-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                        <span class="flex gap-1.5">
                            <span class="size-2.5 rounded-full border border-gray-300"></span>
                            <span class="size-2.5 rounded-full border border-gray-300"></span>
                            <span class="size-2.5 rounded-full border border-gray-300"></span>
                        </span>
                        <span class="flex-1 truncate rounded-md bg-gray-100 px-3 py-1 font-mono text-xs text-gray-500">
                            https://<span class="text-gray-900">{{ $__shopHost }}</span>
                        </span>
                        <span class="relative text-gray-700">
                            @include('site.partials.icon', ['name' => 'shopping-cart', 'class' => 'size-5'])
                            <span class="absolute -right-1.5 -top-1.5 inline-flex size-4 items-center justify-center rounded-full bg-brand-600 text-[9px] font-semibold text-white">3</span>
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 p-4">
                        @foreach((array) __('site.shop.categories') as $i => $category)
                            <div class="rounded-lg border border-gray-200 p-3 transition-colors group-hover:border-gray-300">
                                <div class="flex h-16 items-center justify-center rounded-md bg-gray-50 text-gray-400">
                                    @include('site.partials.icon', ['name' => ['tag', 'archive-box', 'qr-code', 'printer'][$i] ?? 'cube', 'class' => 'size-7'])
                                </div>
                                <p class="mt-2.5 text-xs font-semibold text-gray-900 truncate">{{ $category }}</p>
                                <div class="mt-2 flex items-center justify-between">
                                    <span class="h-1.5 w-12 rounded-full bg-gray-200"></span>
                                    <span class="inline-flex size-6 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-brand-700">
                                        @include('site.partials.icon', ['name' => 'shopping-cart', 'class' => 'size-3.5'])
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </a>
            </div>
        </div>
    </div>
</section>
@endif
