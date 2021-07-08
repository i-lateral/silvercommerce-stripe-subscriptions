<p>
    <%t StripeSubscriptions.PaymentCardsContent "Below are your saved cards, if your card isn't below, please:" %>
    <a href="{$Link('addcard')}">
        <%t StripeSubscriptions.AddNewCard "Add a new card" %>
    </a>
</p>

<% loop $Cards %>
    <div class="card">
        <div class="card-body">
            {$Brand} - <strong>{$CardNumber}</strong>
            <small>{$Expires}</small>
            <a class="float-right" href="{$RemoveLink}">
                <%t StripeSubscriptions.RemoveCard "Remove Card" %>
            </a>
        </div>
    </div>
<% end_loop %>

<% if not $Cards.exists %><p class="alert alert-info">
    <%t StripeSubscriptions.NoSavedCards "You currently have no saved cards" %>
</p><% end_if %>