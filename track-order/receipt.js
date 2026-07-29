"use strict";

document.querySelector("[data-print-receipt]")?.addEventListener("click", () => {
  window.print();
});

document.querySelector("[data-buy-again]")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  const itemsElement = document.querySelector("#reorder-items");
  if (!itemsElement) return;

  button.disabled = true;
  button.textContent = "Checking availability…";

  try {
    const orderedItems = JSON.parse(itemsElement.textContent);
    const response = await fetch("/products.php", {
      headers: { "Accept": "application/json" },
      cache: "no-store"
    });
    const result = await response.json();
    if (!response.ok || result.status !== "success" || !Array.isArray(result.products)) {
      throw new Error("The catalogue is temporarily unavailable.");
    }

    const available = new Map(
      result.products
        .filter(product => product.purchasable && product.inStock !== false)
        .map(product => [product.id, product])
    );
    const cart = JSON.parse(localStorage.getItem("inbornfoot_cart_v2") || "{}");
    let added = 0;

    for (const item of orderedItems) {
      const product = available.get(item.productId);
      if (!product) continue;
      const requested = Math.min(Number(item.quantity) || 0, 20);
      const stockLimit = product.trackStock ? product.stockQuantity : 20;
      const quantity = Math.min((Number(cart[item.productId]) || 0) + requested, 20, stockLimit);
      if (quantity > 0) {
        cart[item.productId] = quantity;
        added += 1;
      }
    }

    if (!added) throw new Error("These products are not currently available.");
    localStorage.setItem("inbornfoot_cart_v2", JSON.stringify(cart));
    window.location.assign("/#products");
  } catch (error) {
    button.disabled = false;
    button.textContent = error instanceof Error ? error.message : "Please try again";
  }
});
