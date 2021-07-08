<p>
    <%t StripeSubscriptions.CurrentSubscriptionsContent "Below are your current Subscriptions. If one has expired, use 'Renew Now' to renew them." %>
</p>

<% loop $CurrentMember.StripePlans %>
    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-12 col-md-4">
                    <strong>{$Plan.Title}</strong>
                    <small><em>({$Expires.Nice})</em></small>
                </div>
                <div class="col-12 col-md-4">
                    <em>{$Status}</em>
                    {$DefaultPaymentMethod}
                </div>
                <div class="col-12 col-md-4">
                    <% if $IsActive %>
                        <a class="btn btn-sm btn-link float-right" href="{$CancelLink}">
                            <%t StripeSubscriptions.Cancel "Cancel" %>
                        </a>
                    <% end_if %>
                    <a class="btn btn-sm btn-link float-right" href="{$RenewLink}">
                        <%t StripeSubscriptions.RenewNow "Renew Now" %>
                    </a>
                </div>
            </div>
        </div>
    </div>
<% end_loop %>