{{--
    The only moment the product has grid geometry to hold open and no data to
    put in it: a connected athlete's first sync, still in flight. Reuses
    .hm-cell for the skeleton cells so the geometry is the grid's, not a copy
    of it — nothing shifts when data lands. The table is aria-hidden so a
    screen reader is never walked through empty cells; aria-busy plus the
    polite live region on the card is the actual announcement.
--}}
<div class="card dash-grid p-3 sm:p-5" aria-busy="true" aria-live="polite">
    <span class="sr-only">Loading your heatmap…</span>
    <table class="hm" aria-hidden="true">
        <colgroup>
            <col class="hm-col-week">
            <col span="7" class="hm-col-day">
            <col class="hm-col-total">
        </colgroup>
        <tbody>
            @for ($row = 0; $row < 6; $row++)
                <tr class="hm-row">
                    <th class="hm-th-week"><span class="skeleton block h-2.5 w-8"></span></th>
                    @for ($day = 0; $day < 7; $day++)
                        <td class="hm-td"><span class="hm-cell skeleton"></span></td>
                    @endfor
                    <td class="hm-total"><span class="skeleton block h-2.5 w-10"></span></td>
                </tr>
            @endfor
        </tbody>
    </table>
</div>
