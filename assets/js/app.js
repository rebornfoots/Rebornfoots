"use strict";

let PRODUCTS = {
  "karupatti-500": { name: "Pure Palm Jaggery", variant: "500 g", price: 420 },
  "cow-butter-1kg": { name: "Pure Cow Butter", variant: "1 kg · 2 × 500 g", price: 820 },
  "buffalo-butter-1kg": { name: "Fresh White Butter", variant: "1 kg · 2 × 500 g", price: 840 },
  "cow-ghee-1l": { name: "Traditional Cow Ghee", variant: "1 litre · 2 × 500 ml", price: 1020 },
  "buffalo-ghee-1l": { name: "Village-Style Ghee", variant: "1 litre · 2 × 500 ml", price: 1050 }
};

const STORAGE_KEY = "inbornfoot_cart_v2";
let cart = loadCart();
let checkoutRequestId = null;
let toastTimer;
let deliveryQuoteState = null;
let deliveryQuoteTimer;
let deliveryQuoteSequence = 0;

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
  customerPincode: document.querySelector("#customerPincode"),
  deliveryQuote: document.querySelector("#deliveryQuote"),
  couponCode: document.querySelector("#couponCode"),
  applyCouponButton: document.querySelector("#applyCouponButton"),
  promotionMessage: document.querySelector("#promotionMessage"),
  checkoutSubtotal: document.querySelector("#checkoutSubtotal"),
  checkoutDeliveryFee: document.querySelector("#checkoutDeliveryFee"),
  comboDiscountRow: document.querySelector("#comboDiscountRow"),
  couponDiscountRow: document.querySelector("#couponDiscountRow"),
  checkoutComboDiscount: document.querySelector("#checkoutComboDiscount"),
  checkoutCouponDiscount: document.querySelector("#checkoutCouponDiscount"),
  checkoutTotal: document.querySelector("#checkoutTotal"),
  placeOrderButton: document.querySelector("#placeOrderButton"),
  formMessage: document.querySelector("#formMessage"),
  orderSuccessDialog: document.querySelector("#orderSuccessDialog"),
  successOrderId: document.querySelector("#successOrderId"),
  closeOrderSuccess: document.querySelector("#closeOrderSuccess"),
  toast: document.querySelector("#toast"),
  comboSection: document.querySelector("#combos"),
  comboGrid: document.querySelector("#comboGrid")
};

function loadCart() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
    return Object.fromEntries(
      Object.entries(saved).filter(([id, quantity]) =>
        /^[a-z0-9-]{2,64}$/.test(id) && Number.isInteger(quantity) && quantity > 0 && quantity <= 20
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

function cartEntries() {
  return Object.entries(cart).filter(([id]) => PRODUCTS[id]);
}

function money(value) {
  return new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: 0
  }).format(value);
}

function cartTotal() {
  return cartEntries().reduce((total, [id, quantity]) =>
    total + PRODUCTS[id].price * quantity, 0
  );
}

function renderCheckoutTotal() {
  const subtotal = cartTotal();
  const fee = deliveryQuoteState?.serviceable ? deliveryQuoteState.deliveryFee : 0;
  const comboDiscount = deliveryQuoteState?.comboDiscount || 0;
  const couponDiscount = deliveryQuoteState?.couponDiscount || 0;
  elements.checkoutSubtotal.textContent = money(subtotal);
  elements.checkoutDeliveryFee.textContent = deliveryQuoteState?.serviceable
    ? (fee > 0 ? money(fee) : "Free")
    : "—";
  elements.comboDiscountRow.hidden = comboDiscount <= 0;
  elements.couponDiscountRow.hidden = couponDiscount <= 0;
  elements.checkoutComboDiscount.textContent = `−${money(comboDiscount)}`;
  elements.checkoutCouponDiscount.textContent = `−${money(couponDiscount)}`;
  elements.checkoutTotal.textContent = money(Math.max(0, subtotal - comboDiscount - couponDiscount) + fee);
}

function resetDeliveryQuote(message = "Enter your PIN code to check delivery.") {
  deliveryQuoteState = null;
  elements.deliveryQuote.textContent = message;
  elements.deliveryQuote.className = "delivery-quote";
  elements.promotionMessage.textContent = "Combo offers apply automatically.";
  elements.promotionMessage.className = "delivery-quote";
  renderCheckoutTotal();
}

async function loadDeliveryQuote(pincode) {
  const sequence = ++deliveryQuoteSequence;
  elements.deliveryQuote.textContent = "Checking delivery…";
  elements.deliveryQuote.className = "delivery-quote";
  try {
    const couponCode = elements.couponCode.value.trim().toUpperCase();
    elements.couponCode.value = couponCode;
    const response = await fetch("pricing_quote.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        pincode,
        couponCode,
        items: Object.entries(cart).map(([productId, quantity]) => ({ productId, quantity }))
      })
    });
    const result = await response.json().catch(() => ({}));
    if (sequence !== deliveryQuoteSequence) return null;
    if (!response.ok || result.status !== "success" || !result.serviceable) {
      throw new Error(result.message || "Delivery is unavailable for this PIN code.");
    }
    deliveryQuoteState = {
      pincode,
      serviceable: true,
      deliveryFee: Number(result.deliveryFee) || 0,
      minDays: Number(result.minDays),
      maxDays: Number(result.maxDays),
      areaName: result.areaName || "",
      comboDiscount: Number(result.comboDiscount) || 0,
      couponDiscount: Number(result.couponDiscount) || 0,
      discountTotal: Number(result.discountTotal) || 0,
      couponCode: result.couponCode || "",
      combos: Array.isArray(result.combos) ? result.combos : []
    };
    const area = deliveryQuoteState.areaName ? `${deliveryQuoteState.areaName} · ` : "";
    const fee = deliveryQuoteState.deliveryFee > 0 ? money(deliveryQuoteState.deliveryFee) : "Free delivery";
    elements.deliveryQuote.textContent =
      `${area}${fee} · Estimated ${deliveryQuoteState.minDays}–${deliveryQuoteState.maxDays} days`;
    elements.deliveryQuote.className = "delivery-quote success";
    const messages = [];
    if (deliveryQuoteState.combos.length) {
      messages.push(`Combo savings ${money(deliveryQuoteState.comboDiscount)} applied`);
    }
    if (deliveryQuoteState.couponCode) {
      messages.push(`Coupon ${deliveryQuoteState.couponCode} applied`);
    }
    elements.promotionMessage.textContent = messages.length
      ? messages.join(" · ")
      : "No promotion applied. Combo offers activate automatically.";
    elements.promotionMessage.className = `delivery-quote${messages.length ? " success" : ""}`;
    renderCheckoutTotal();
    return deliveryQuoteState;
  } catch (error) {
    if (sequence !== deliveryQuoteSequence) return null;
    deliveryQuoteState = null;
    const couponAttempted = elements.couponCode.value.trim() !== "";
    const messageElement = couponAttempted ? elements.promotionMessage : elements.deliveryQuote;
    messageElement.textContent = error.message;
    messageElement.className = "delivery-quote error";
    renderCheckoutTotal();
    throw error;
  }
}

function cartQuantity() {
  return cartEntries().reduce((total, [, quantity]) => total + quantity, 0);
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

  cartEntries().forEach(([id, quantity]) => {
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
  renderCheckoutTotal();
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
  renderCheckoutTotal();
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

function safeImageUrl(value) {
  try {
    const url = new URL(value, window.location.href);
    if (url.origin === window.location.origin || url.hostname === "images.unsplash.com") {
      return url.href;
    }
  } catch {
    // Invalid catalogue image URLs use the neutral card background.
  }
  return "";
}

function productCard(product) {
  const card = document.createElement("article");
  card.className = "product-card";
  if (product.purchasable && product.price !== null) {
    card.dataset.productId = product.id;
  }

  const image = document.createElement("div");
  image.className = "product-image";
  image.setAttribute("role", "img");
  image.setAttribute("aria-label", product.altText || product.name);
  const imageUrl = safeImageUrl(product.imageUrl);
  if (imageUrl) image.style.backgroundImage = `url("${imageUrl}")`;

  if (product.badge) {
    const badge = document.createElement("span");
    badge.className = "product-badge";
    badge.textContent = product.badge;
    image.append(badge);
  }
  if (product.lowStock) {
    const stockBadge = document.createElement("span");
    stockBadge.className = "product-stock-badge";
    stockBadge.textContent = "Only a few left";
    image.append(stockBadge);
  }

  const content = document.createElement("div");
  content.className = "product-content";
  const meta = document.createElement("div");
  meta.className = "product-meta";
  const category = document.createElement("span");
  category.textContent = product.category;
  const quality = document.createElement("span");
  quality.textContent = product.purchasable ? "★★★★★" : "Natural";
  meta.append(category, quality);

  const title = document.createElement("h3");
  title.textContent = product.name;
  const description = document.createElement("p");
  description.textContent = product.description;
  content.append(meta, title, description);

  if (Array.isArray(product.benefits) && product.benefits.length) {
    const benefits = document.createElement("ul");
    benefits.className = "product-benefits";
    product.benefits.slice(0, 5).forEach(value => {
      const item = document.createElement("li");
      item.textContent = value;
      benefits.append(item);
    });
    content.append(benefits);
  }

  const buy = document.createElement("div");
  buy.className = "product-buy";
  const pricing = document.createElement("div");
  const price = document.createElement("strong");
  const variant = document.createElement("small");
  variant.textContent = product.variant;
  const delivery = document.createElement("small");
  delivery.className = "delivery-note";
  delivery.textContent = "Delivery calculated by PIN code";
  pricing.append(price, variant, delivery);

  if (product.inStock === false && product.price !== null) {
    price.textContent = money(product.price);
    const button = document.createElement("button");
    button.className = "add-button";
    button.type = "button";
    button.textContent = "Out of stock";
    button.disabled = true;
    buy.append(pricing, button);
  } else if (product.purchasable && product.price !== null) {
    price.textContent = money(product.price);
    const button = document.createElement("button");
    button.className = "add-button";
    button.type = "button";
    button.textContent = "Add to cart";
    button.addEventListener("click", () => addToCart(product.id));
    buy.append(pricing, button);
  } else {
    price.className = "contact-price";
    price.textContent = "Contact for price";
    const link = document.createElement("a");
    link.className = "add-button contact-button";
    link.href = "tel:+918110007172";
    link.textContent = "Call to order";
    buy.append(pricing, link);
  }

  content.append(buy);
  card.append(image, content);
  return card;
}

async function loadProducts() {
  try {
    const response = await fetch("products.php", { headers: { "Accept": "application/json" } });
    const result = await response.json();
    if (!response.ok || result.status !== "success" || !Array.isArray(result.products) || !result.products.length) {
      throw new Error("Catalogue unavailable");
    }

    const nextProducts = {};
    result.products.forEach(product => {
      if (product.purchasable && Number.isFinite(product.price)) {
        nextProducts[product.id] = {
          name: product.name,
          variant: product.variant,
          price: product.price
        };
      }
    });
    PRODUCTS = nextProducts;
    cart = Object.fromEntries(Object.entries(cart).filter(([id]) => PRODUCTS[id]));

    const grid = document.querySelector(".product-grid");
    const cards = result.products.map(productCard);
    grid.replaceChildren(...cards);
    saveCart();
  } catch {
    // The server-rendered catalogue remains usable if the API or DB is unavailable.
  }
}

async function loadCombos() {
  if (!elements.comboSection || !elements.comboGrid) return;
  try {
    const response = await fetch("combos.php", { headers: { "Accept": "application/json" } });
    const result = await response.json();
    if (!response.ok || result.status !== "success" || !Array.isArray(result.combos)) {
      throw new Error("Combo catalogue unavailable");
    }
    const combos = result.combos.filter(combo =>
      combo.available && Array.isArray(combo.items) && combo.items.length >= 2
      && combo.items.every(item => PRODUCTS[item.productId])
    );
    elements.comboGrid.replaceChildren();
    combos.forEach(combo => {
      const card = document.createElement("article");
      card.className = "combo-card";

      const visual = document.createElement("div");
      visual.className = "combo-visual";
      if (combo.imageUrl) {
        const image = document.createElement("img");
        image.src = combo.imageUrl;
        image.alt = combo.name;
        image.loading = "lazy";
        visual.append(image);
      } else {
        visual.classList.add("combo-collage");
        combo.items.slice(0, 3).forEach(item => {
          const image = document.createElement("img");
          image.src = item.imageUrl;
          image.alt = "";
          image.loading = "lazy";
          visual.append(image);
        });
      }
      const body = document.createElement("div");
      body.className = "combo-body";
      const eyebrow = document.createElement("p");
      eyebrow.className = "combo-saving";
      eyebrow.textContent = `Save ${money(Number(combo.discount) || 0)}`;
      const title = document.createElement("h3");
      title.textContent = combo.name;
      const items = document.createElement("ul");
      items.className = "combo-items";
      combo.items.forEach(item => {
        const line = document.createElement("li");
        line.textContent = `${Number(item.quantity)} × ${item.name} (${item.variant})`;
        items.append(line);
      });
      const footer = document.createElement("div");
      footer.className = "combo-footer";
      const price = document.createElement("div");
      price.className = "combo-price";
      const current = document.createElement("strong");
      current.textContent = money(Number(combo.comboPrice));
      const original = document.createElement("s");
      original.textContent = money(Number(combo.originalPrice));
      price.append(current, original);
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = "Add full combo to cart";
      button.addEventListener("click", () => {
        combo.items.forEach(item => {
          const quantity = Number(item.quantity) || 1;
          cart[item.productId] = Math.min(20, (cart[item.productId] || 0) + quantity);
        });
        saveCart();
        showToast(`${combo.name} added to your cart`);
        openCart();
      });
      footer.append(price, button);
      body.append(eyebrow, title, items, footer);
      card.append(visual, body);
      elements.comboGrid.append(card);
    });
    elements.comboSection.hidden = combos.length === 0;
  } catch (error) {
    console.warn(error.message);
    elements.comboSection.hidden = true;
  }
}

async function submitOrder(event) {
  event.preventDefault();
  elements.formMessage.hidden = true;

  if (!elements.checkoutForm.reportValidity() || !cartQuantity()) return;

  const formData = new FormData(elements.checkoutForm);
  const pincode = formData.get("pincode").trim();
  const requestedCoupon = elements.couponCode.value.trim().toUpperCase();
  if (!deliveryQuoteState
      || deliveryQuoteState.pincode !== pincode
      || deliveryQuoteState.couponCode !== requestedCoupon) {
    clearTimeout(deliveryQuoteTimer);
    try {
      const quote = await loadDeliveryQuote(pincode);
      if (!quote) return;
    } catch {
      return;
    }
  }
  checkoutRequestId ||= crypto.randomUUID();
  const payload = {
    requestId: checkoutRequestId,
    name: formData.get("name").trim(),
    phone: formData.get("phone").trim(),
    address: formData.get("address").trim(),
    pincode,
    couponCode: elements.couponCode.value.trim().toUpperCase(),
    payment: "Razorpay",
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
    deliveryQuoteState = {
      pincode,
      serviceable: true,
      deliveryFee: Number(result.deliveryFee) || 0,
      comboDiscount: Number(result.comboDiscount) || 0,
      couponDiscount: Number(result.couponDiscount) || 0,
      discountTotal: Number(result.discountTotal) || 0,
      couponCode: result.couponCode || "",
      combos: Array.isArray(result.combos) ? result.combos : []
    };
    renderCheckoutTotal();

    if (typeof window.Razorpay !== "function") {
      throw new Error("Secure payment checkout could not load. Check your connection and try again.");
    }

    closeCheckout();
    await new Promise((resolve, reject) => {
      const checkout = new window.Razorpay({
        key: result.razorpayKeyId,
        order_id: result.razorpayOrderId,
        amount: result.amountPaise,
        currency: "INR",
        name: "InbornFoot",
        description: `Order #${result.orderId}`,
        prefill: {
          name: result.customer.name,
          contact: result.customer.phone
        },
        theme: { color: "#174f35" },
        modal: {
          ondismiss: () => reject(new Error("Payment was cancelled. Your cart is still saved."))
        },
        handler: async payment => {
          try {
            const verification = await fetch("payment_verify.php", {
              method: "POST",
              headers: { "Content-Type": "application/json", "Accept": "application/json" },
              body: JSON.stringify({
                orderId: result.orderId,
                razorpayOrderId: payment.razorpay_order_id,
                razorpayPaymentId: payment.razorpay_payment_id,
                razorpaySignature: payment.razorpay_signature
              })
            });
            const verified = await verification.json().catch(() => ({}));
            if (!verification.ok || verified.status !== "success") {
              throw new Error(verified.message || "Payment verification failed. Please contact us.");
            }
            resolve();
          } catch (error) {
            reject(error);
          }
        }
      });
      checkout.on("payment.failed", response => {
        reject(new Error(response.error?.description || "Payment failed. Please try again."));
      });
      checkout.open();
    });

    cart = {};
    saveCart();
    elements.checkoutForm.reset();
    resetDeliveryQuote();
    closeCheckout();
    elements.successOrderId.textContent = result.orderId;
    elements.orderSuccessDialog.showModal();
    checkoutRequestId = null;
  } catch (error) {
    if (!elements.checkoutDialog.open) elements.checkoutDialog.showModal();
    elements.formMessage.textContent = error.message;
    elements.formMessage.hidden = false;
  } finally {
    elements.placeOrderButton.disabled = false;
    elements.placeOrderButton.textContent = "Place order";
  }
}

document.querySelectorAll(".product-card[data-product-id]").forEach(card => {
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
elements.closeOrderSuccess.addEventListener("click", () => elements.orderSuccessDialog.close());
elements.checkoutForm.addEventListener("submit", submitOrder);
elements.customerPincode.addEventListener("input", () => {
  checkoutRequestId = null;
  clearTimeout(deliveryQuoteTimer);
  const pincode = elements.customerPincode.value.replace(/\D/g, "").slice(0, 6);
  elements.customerPincode.value = pincode;
  if (pincode.length !== 6) {
    ++deliveryQuoteSequence;
    resetDeliveryQuote();
    return;
  }
  resetDeliveryQuote("Checking delivery…");
  deliveryQuoteTimer = setTimeout(() => {
    loadDeliveryQuote(pincode).catch(() => {});
  }, 300);
});
elements.applyCouponButton.addEventListener("click", () => {
  checkoutRequestId = null;
  clearTimeout(deliveryQuoteTimer);
  const pincode = elements.customerPincode.value.trim();
  if (!/^[1-9]\d{5}$/.test(pincode)) {
    elements.deliveryQuote.textContent = "Enter a valid PIN code first.";
    elements.deliveryQuote.className = "delivery-quote error";
    return;
  }
  loadDeliveryQuote(pincode).catch(() => {});
});
elements.couponCode.addEventListener("input", () => {
  checkoutRequestId = null;
  deliveryQuoteState = null;
  elements.promotionMessage.textContent = "Click Apply to validate this coupon.";
  elements.promotionMessage.className = "delivery-quote";
  renderCheckoutTotal();
});
elements.checkoutDialog.addEventListener("click", event => {
  if (event.target === elements.checkoutDialog) closeCheckout();
});
document.addEventListener("keydown", event => {
  if (event.key === "Escape" && elements.cartDrawer.classList.contains("open")) closeCart();
});

document.querySelector("#currentYear").textContent = new Date().getFullYear();
renderCart();
(async () => {
  await loadProducts();
  await loadCombos();
})();
