import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@features/auth/useAuth';

/** App chrome with navigation, shared by catalogue pages. */
export function Layout({ children }: { children: ReactNode }): React.JSX.Element {
  const { user } = useAuth();

  return (
    <div>
      <header className="nav">
        <Link to="/" className="nav__brand">
          EruoFood AI
        </Link>
        <nav className="nav__links">
          <Link to="/">Foods</Link>
          <Link to="/recipes">Recipes</Link>
          <Link to="/vendors">Vendors</Link>
          <Link to="/shop">Shop</Link>
          {user ? (
            <>
              <Link to="/cart">Cart</Link>
              <Link to="/shop-cart">Grocery Cart</Link>
              <Link to="/wishlist">Wishlist</Link>
              <Link to="/orders">Orders</Link>
              <Link to="/wallet-account">Wallet</Link>
              <Link to="/transactions">Payments</Link>
              <Link to="/notifications">Alerts</Link>
              <Link to="/messages">Messages</Link>
              <Link to="/favourites">Favourites</Link>
              <Link to="/ai/recipe-generator">AI Chef</Link>
              <Link to="/ai/assistant">Assistant</Link>
              <Link to="/nutrition">Nutrition</Link>
              {user.roles.includes('admin') ? <Link to="/analytics">Analytics</Link> : null}
              {user.roles.includes('admin') ? <Link to="/admin/foods">Admin</Link> : null}
              <Link to="/account">Account</Link>
            </>
          ) : (
            <Link to="/login">Sign in</Link>
          )}
        </nav>
      </header>
      <main className="container">{children}</main>
    </div>
  );
}
