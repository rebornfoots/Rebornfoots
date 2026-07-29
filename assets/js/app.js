"use strict";

const PRODUCTS = {
  "karupatti-500": { name: "Pure Palm Jaggery", variant: "500 g", price: 420 },
  "cow-butter-1kg": { name: "Pure Cow Butter", variant: "1 kg · 2 × 500 g", price: 820 },
  "buffalo-butter-1kg": { name: "Fresh White Butter", variant: "1 kg · 2 × 500 g", price: 840 },
  "cow-ghee-1l": { name: "Traditional Cow Ghee", variant: "1 litre · 2 × 500 ml", price: 1020 },
  "buffalo-ghee-1l": { name: "Village-Style Ghee", variant: "1 litre · 2 × 500 ml", price: 1050 }
};

const STORAGE_KEY = "farmers2home_cart_v2";
let cart = loadCart();
let toastTimer;

const elements = {
  cartTrigger: document.querySelector("#cartTrigger"),
  cartDrawer: document.querySelector("#cartDrawer"),
  drawerBackdrop: document.querySelector("#drawerBackdrop"),
  closeCart: document.querySelector("#closeCart"),
  continueShopping: document.querySelector("#continueShopping"),
  cartCount: document.querySelector("#cartCount"),
  cartItems: document.querySelector("#cartItems"),
  emptyCart: document.querySelector("#emptyCart"),
  cartSummary: document.querySelector("#cartSummary"),
  cartSubtotal: document.querySelector("#cartSubtotal"),
  checkoutButton: document.querySelector("#checkoutButton"),
  checkoutDialog: document.querySelector("#checkoutDialog"),
  closeCheckout: document.querySelector("#closeCheckout"),
  checkoutForm: document.querySelector("#checkoutForm"),
  checkoutTotal: document.querySelector("#checkoutTotal"),
  placeOrderButton: document.querySelector("#placeOrderButton"),
  formMessage: document.querySelector("#formMessage"),
  toast: document.querySelector("#toast")
};

function loadCart() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
    return Object.fromEntries(
      Object.entries(saved).filter(([id, quantity]) =>
        PRODUCTS[id] && Number.isInteger(quantity) && quantity > 0 && quantity <= 20
      )
    );
  } catch {
    return {};
  }
}

function saveCart() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
  renderCart();
}

function money(value) {
  return new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: 0
  }).format(value);
}

function cartTotal() {
  return Object.entries(cart).reduce((total, [id, quantity]) =>
    total + PRODUCTS[id].price * quantity, 0
  );
}

function cartQuantity() {
  return Object.values(cart).reduce((total, quantity) => total + quantity, 0);
}

function addToCart(id) {
  cart[id] = Math.min((cart[id] || 0) + 1, 20);
  saveCart();
  showToast(`${PRODUCTS[id].name} added to your cart`);
}

function updateQuantity(id, change) {
  const next = (cart[id] || 0) + change;
  if (next <= 0) {
    delete cart[id];
  } else {
    cart[id] = Math.min(next, 20);
  }
  saveCart();
}

function renderCart() {
  const quantity = cartQuantity();
  elements.cartCount.textContent = quantity;
  elements.cartCount.setAttribute("aria-label", `${quantity} item${quantity === 1 ? "" : "s"}`);
  elements.cartItems.replaceChildren();

  Object.entries(cart).forEach(([id, quantity]) => {
    const product = PRODUCTS[id];
    const item = document.createElement("article");
    item.className = "cart-item";
    item.innerHTML = `
      <div>
        <h3>${product.name}</h3>
        <p>${product.variant}</p>
        <div class="quantity-control" aria-label="Quantity for ${product.name}">
          <button type="button" data-action="decrease" data-id="${id}" aria-label="Decrease quantity">−</button>
          <span>${quantity}</span>
          <button type="button" data-action="increase" data-id="${id}" aria-label="Increase quantity">+</button>
        </div>
      </div>
      <strong class="cart-item-price">${money(product.price * quantity)}</strong>
      <button class="remove-item" type="button" data-action="remove" data-id="${id}">Remove</button>
    `;
    elements.cartItems.append(item);
  });

  const empty = quantity === 0;
  elements.emptyCart.hidden = !empty;
  elements.cartSummary.hidden = empty;
  elements.cartSubtotal.textContent = money(cartTotal());
  elements.checkoutTotal.textContent = money(cartTotal());
}

function openCart() {
  elements.cartDrawer.classList.add("open");
  elements.cartDrawer.setAttribute("aria-hidden", "false");
  elements.cartTrigger.setAttribute("aria-expanded", "true");
  elements.drawerBackdrop.hidden = false;
  document.body.classList.add("no-scroll");
  elements.closeCart.focus();
}

function closeCart() {
  elements.cartDrawer.classList.remove("open");
  elements.cartDrawer.setAttribute("aria-hidden", "true");
  elements.cartTrigger.setAttribute("aria-expanded", "false");
  elements.drawerBackdrop.hidden = true;
  document.body.classList.remove("no-scroll");
}

function openCheckout() {
  if (!cartQuantity()) return;
  closeCart();
  elements.checkoutTotal.textContent = money(cartTotal());
  elements.checkoutDialog.showModal();
  document.querySelector("#customerName").focus();
}

function closeCheckout() {
  elements.checkoutDialog.close();
}

function showToast(message) {
  clearTimeout(toastTimer);
  elements.toast.textContent = message;
  elements.toast.classList.add("show");
  toastTimer = setTimeout(() => elements.toast.classList.remove("show"), 2600);
}

async function submitOrder(event) {
  event.preventDefault();
  elements.formMessage.hidden = true;

  if (!elements.checkoutForm.reportValidity() || !cartQuantity()) return;

  const formData = new FormData(elements.checkoutForm);
  const payload = {
    name: formData.get("name").trim(),
    phone: formData.get("phone").trim(),
    address: formData.get("address").trim(),
    payment: "UPI",
    items: Object.entries(cart).map(([productId, quantity]) => ({ productId, quantity }))
  };

  elements.placeOrderButton.disabled = true;
  elements.placeOrderButton.textContent = "Placing your order…";

  try {
    const response = await fetch("place_order.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload)
    });
    const result = await response.json().catch(() => ({}));

    if (!response.ok || result.status !== "success") {
      throw new Error(result.message || "We couldn’t place your order. Please try again.");
    }

    cart = {};
    saveCart();
    elements.checkoutForm.reset();
    closeCheckout();
    showToast(`Order #${result.orderId} placed successfully`);
  } catch (error) {
    elements.formMessage.textContent = error.message;
    elements.formMessage.hidden = false;
  } finally {
    elements.placeOrderButton.disabled = false;
    elements.placeOrderButton.textContent = "Place order";
  }
}

document.querySelectorAll(".product-card").forEach(card => {
  card.querySelector(".add-button").addEventListener("click", () => addToCart(card.dataset.productId));
});

elements.cartItems.addEventListener("click", event => {
  const button = event.target.closest("button[data-action]");
  if (!button) return;
  const { action, id } = button.dataset;
  if (action === "increase") updateQuantity(id, 1);
  if (action === "decrease") updateQuantity(id, -1);
  if (action === "remove") {
    delete cart[id];
    saveCart();
  }
});

elements.cartTrigger.addEventListener("click", openCart);
elements.closeCart.addEventListener("click", closeCart);
elements.drawerBackdrop.addEventListener("click", closeCart);
elements.continueShopping.addEventListener("click", closeCart);
elements.checkoutButton.addEventListener("click", openCheckout);
elements.closeCheckout.addEventListener("click", closeCheckout);
elements.checkoutForm.addEventListener("submit", submitOrder);
elements.checkoutDialog.addEventListener("click", event => {
  if (event.target === elements.checkoutDialog) closeCheckout();
});
document.addEventListener("keydown", event => {
  if (event.key === "Escape" && elements.cartDrawer.classList.contains("open")) closeCart();
});

document.querySelector("#currentYear").textContent = new Date().getFullYear();
renderCart();
