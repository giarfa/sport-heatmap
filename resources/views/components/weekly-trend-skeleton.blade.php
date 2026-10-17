{{--
    The trend card while the grid shows its first-sync skeleton: sixteen bars at fixed
    heights, aria-hidden because the grid skeleton already announces the load. Reuses
    .skeleton, so the pulse and its prefers-reduced-motion rule are the grid's.
--}}
<section class="card p-4 trend" aria-labelledby="trend-heading">
    <h3 id="trend-heading" class="trend-title">Weekly distance</h3>
    <div class="trend-chart" aria-hidden="true">
        <div class="trend-y"></div>
        <div class="trend-plot trend-plot--skeleton">
                <span class="skeleton" style="height: 35%"></span>
                <span class="skeleton" style="height: 48%"></span>
                <span class="skeleton" style="height: 42%"></span>
                <span class="skeleton" style="height: 60%"></span>
                <span class="skeleton" style="height: 55%"></span>
                <span class="skeleton" style="height: 70%"></span>
                <span class="skeleton" style="height: 64%"></span>
                <span class="skeleton" style="height: 50%"></span>
                <span class="skeleton" style="height: 30%"></span>
                <span class="skeleton" style="height: 45%"></span>
                <span class="skeleton" style="height: 62%"></span>
                <span class="skeleton" style="height: 74%"></span>
                <span class="skeleton" style="height: 70%"></span>
                <span class="skeleton" style="height: 82%"></span>
                <span class="skeleton" style="height: 58%"></span>
                <span class="skeleton" style="height: 40%"></span>
        </div>
    </div>
</section>
