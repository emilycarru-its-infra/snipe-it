{{-- Storefront behaviour: the product grid's quantity steppers and the cart.
     Same philosophy as the PO builder — everything client-side until one
     POST places the order. --}}
<style>
    .st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px; }
    .st-card { background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 14px; display: flex; flex-direction: column; }
    .st-card-img { height: 130px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; color: #ccc; font-size: 40px; }
    .st-card-img img { max-height: 130px; max-width: 100%; object-fit: contain; }
    .st-card-name { font-size: 13px; font-weight: 600; flex: 1 0 auto; margin-bottom: 4px; }
    .st-card-price { color: #666; font-size: 12.5px; margin-bottom: 10px; }
    .st-card-actions { display: flex; gap: 8px; align-items: center; }
    /* Dedicated stepper buttons — big enough to actually hit. */
    .st-qty { display: flex; align-items: stretch; }
    .st-qty .btn { width: 34px; padding: 6px 0; font-size: 16px; line-height: 1; font-weight: 700; }
    .st-qty .st-minus { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .st-qty .st-plus { border-top-left-radius: 0; border-bottom-left-radius: 0; }
    .st-qty-input { width: 46px; text-align: center; border-radius: 0; border-left: 0; border-right: 0; padding: 6px 2px;
                    -moz-appearance: textfield; }
    .st-qty-input::-webkit-outer-spin-button, .st-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .st-add { flex: 1 1 auto; }
    .st-cart { list-style: none; margin: 0; padding: 0; }
    .st-cart li { display: flex; justify-content: space-between; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 12.5px; }
    .st-cart .st-cart-qty { color: #666; white-space: nowrap; }
    .st-cart .st-cart-remove { color: #a94442; cursor: pointer; }
    .st-cart-box { position: sticky; top: 60px; }
    @media (max-width: 991px) { .st-cart-box { position: static; } }
</style>
<script>
(function () {
    var form = document.getElementById('st-form');
    if (! form) { return; }

    var cart = [];
    var cartList = document.getElementById('st-cart');
    var cartEmpty = document.getElementById('st-cart-empty');
    var submit = document.getElementById('st-submit');
    var lineInputs = document.getElementById('st-line-inputs');

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function clampQty(value) {
        var qty = parseInt(value, 10);
        if (isNaN(qty)) { qty = 1; }
        return Math.min(100, Math.max(1, qty));
    }

    function render() {
        var html = '';
        for (var i = 0; i < cart.length; i++) {
            html += '<li data-line="' + i + '">'
                + '<span>' + escapeHtml(cart[i].name) + '</span>'
                + '<span class="st-cart-qty">&times;' + cart[i].quantity
                + ' <a href="#" class="st-cart-remove" aria-label="remove">&times;</a></span>'
                + '</li>';
        }
        cartList.innerHTML = html;
        cartEmpty.hidden = cart.length > 0;
        submit.disabled = cart.length === 0;
    }

    // Steppers + add, delegated across every card.
    document.addEventListener('click', function (e) {
        var minus = e.target.closest('.st-minus');
        var plus = e.target.closest('.st-plus');
        var add = e.target.closest('.st-add');
        if (! minus && ! plus && ! add) { return; }
        e.preventDefault();

        var card = e.target.closest('.st-card');
        if (! card) { return; }
        var input = card.querySelector('.st-qty-input');

        if (minus) { input.value = clampQty(parseInt(input.value, 10) - 1); return; }
        if (plus) { input.value = clampQty(parseInt(input.value, 10) + 1); return; }

        var id = parseInt(card.dataset.itemId, 10);
        var quantity = clampQty(input.value);

        for (var i = 0; i < cart.length; i++) {
            if (cart[i].catalog_item_id === id) {
                cart[i].quantity = clampQty(cart[i].quantity + quantity);
                input.value = 1;
                render();
                return;
            }
        }

        cart.push({ catalog_item_id: id, name: add.dataset.name, quantity: quantity });
        input.value = 1;
        render();
    });

    cartList.addEventListener('click', function (e) {
        var remove = e.target.closest('.st-cart-remove');
        if (! remove) { return; }
        e.preventDefault();
        cart.splice(parseInt(remove.closest('li').dataset.line, 10), 1);
        render();
    });

    form.addEventListener('submit', function (e) {
        if (cart.length === 0) { e.preventDefault(); return; }

        var html = '';
        for (var i = 0; i < cart.length; i++) {
            html += '<input type="hidden" name="items[' + i + '][catalog_item_id]" value="' + cart[i].catalog_item_id + '">'
                + '<input type="hidden" name="items[' + i + '][quantity]" value="' + cart[i].quantity + '">';
        }
        lineInputs.innerHTML = html;
    });

    render();
})();
</script>
