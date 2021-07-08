<% if $IncludeFormTag %>
<form $AttributesHTML>
<% end_if %>
    <div class="card-body">
        <% if $Message %>
        <p id="{$FormName}_error" class="message $MessageType">$Message</p>
        <% else %>
        <p id="{$FormName}_error" class="message $MessageType" style="display: none"></p>
        <% end_if %>

        <fieldset>
            <% if $Legend %><legend>$Legend</legend><% end_if %>
            <% loop $Fields %>
                $FieldHolder
            <% end_loop %>
            <div class="clear"><!-- --></div>
        </fieldset>

        <div class="pt-4 pb-4">
            <div id="stripe-card-elements" class="row">
                <div class="field col-12">
                    <div id="stripe-card-number" class="stripe-field-container border-bottom"></div>
                    <label for="stripe-card-number"><%t StripeSubscriptions.CardNumber "Card number" %></label>
                </div>
            </div>
            <div class="row">
                <div class="col-6">
                    <div id="stripe-card-expiry" class="stripe-field-container border-bottom"></div>
                    <label for="stripe-card-expiry"><%t StripeSubscriptions.Expiration "Expiration" %></label>
                </div>
                <div class="col-6">
                    <div id="stripe-card-cvc" class="stripe-field-container border-bottom"></div>
                    <label for="stripe-card-cvc"><%t StripeSubscriptions.CVC "CVC" %></label>
                </div>
            </div>
        </div>

        <% if $Actions %>
        <div class="btn-toolbar">
            <% loop $Actions %>
                $Field
            <% end_loop %>
        </div>
        <% end_if %>
    <% if $IncludeFormTag %>
    </form>
    <% end_if %>
</div>
