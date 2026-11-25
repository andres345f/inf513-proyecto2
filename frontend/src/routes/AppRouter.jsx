import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { entorno } from '../config/entorno'
import Cargando from '../components/ui/Cargando'
import LayoutPrincipal from '../layouts/LayoutPrincipal'
import LoginPagina from '../pages/auth/LoginPagina'
import RegistroPagina from '../pages/auth/RegistroPagina'
import InicioPagina from '../pages/inicio/InicioPagina'
import PerfilPagina from '../pages/perfil/PerfilPagina'
import MiCuentaPagina from '../pages/cuenta/MiCuentaPagina'
import MiSolicitudPagina from '../pages/cuenta/MiSolicitudPagina'
import CarritoPagina from '../pages/carrito/CarritoPagina'
import CatalogoPagina from '../pages/catalogo/CatalogoPagina'
import ProductoDetallePagina from '../pages/catalogo/ProductoDetallePagina'
import MisPedidosPagina from '../pages/pedidos/MisPedidosPagina'
import MisPagosPagina from '../pages/pagos/MisPagosPagina'
import MetodosPagoPagina from '../pages/pagos/MetodosPagoPagina'
import PlanesPagoPagina from '../pages/pagos/PlanesPagoPagina'
import GestionPedidoDetallePagina from '../pages/gestion/GestionPedidoDetallePagina'
import GestionDevolucionesPagina from '../pages/gestion/GestionDevolucionesPagina'
import MisDevolucionesPagina from '../pages/devoluciones/MisDevolucionesPagina'
import GestionEntregasPagina from '../pages/gestion/GestionEntregasPagina'
import GestionPagosPagina from '../pages/gestion/GestionPagosPagina'
import GestionPedidosPagina from '../pages/gestion/GestionPedidosPagina'
import CategoriasPagina from '../pages/gestion/CategoriasPagina'
import ProductosPagina from '../pages/gestion/ProductosPagina'
import ComunidadesPagina from '../pages/gestion/ComunidadesPagina'
import ProductoresPagina from '../pages/gestion/ProductoresPagina'
import MiFichaPagina from '../pages/productor/MiFichaPagina'
import MisProduccionesPagina from '../pages/productor/MisProduccionesPagina'
import InventarioPagina from '../pages/gestion/InventarioPagina'
import ProduccionesPagina from '../pages/gestion/ProduccionesPagina'
import ComprasProductoresPagina from '../pages/gestion/ComprasProductoresPagina'
import NotificacionesPagina from '../pages/notificaciones/NotificacionesPagina'
import BitacoraPagina from '../pages/admin/BitacoraPagina'
import PermisosPagina from '../pages/admin/PermisosPagina'
import UsuariosPagina from '../pages/admin/UsuariosPagina'
import ReportesPagina from '../pages/panel/ReportesPagina'
import FacturaPagina from '../pages/facturas/FacturaPagina'
import MisFacturasPagina from '../pages/facturas/MisFacturasPagina'
import RutaDinamica from './RutaDinamica'
import RutaInvitado from './RutaInvitado'
import RutaProtegida from './RutaProtegida'

const CheckoutPagina = lazy(() => import('../pages/checkout/CheckoutPagina'))
const PedidoDetallePagina = lazy(() => import('../pages/pedidos/PedidoDetallePagina'))
const MisVentasPagina = lazy(() => import('../pages/productor/MisVentasPagina'))
const PanelProductorPagina = lazy(() => import('../pages/productor/PanelProductorPagina'))
const ProduccionDetallePagina = lazy(() => import('../pages/productor/ProduccionDetallePagina'))
const MisLotesPagina = lazy(() => import('../pages/productor/MisLotesPagina'))
const PropuestasPagina = lazy(() => import('../pages/productor/PropuestasPagina'))
const AccesosPagina = lazy(() => import('../pages/admin/AccesosPagina'))
const PanelAdministracionPagina = lazy(() => import('../pages/admin/PanelAdministracionPagina'))
const PanelPagina = lazy(() => import('../pages/panel/PanelPagina'))
const PanelOperacionesPagina = lazy(() => import('../pages/gestion/PanelOperacionesPagina'))

export default function AppRouter() {
  return (
    <BrowserRouter basename={entorno.basename}>
      <Suspense fallback={<Cargando texto="Cargando página…" />}>
      <Routes>
        <Route element={<LayoutPrincipal />}>
          <Route index element={<InicioPagina />} />
          <Route path="ingresar" element={<RutaInvitado><LoginPagina /></RutaInvitado>} />
          <Route path="registro" element={<RutaInvitado><RegistroPagina /></RutaInvitado>} />
          <Route path="perfil" element={<RutaProtegida permiso="perfil.ver"><PerfilPagina /></RutaProtegida>} />
          <Route path="mi-cuenta" element={<RutaProtegida permiso="mi_cuenta.ver"><MiCuentaPagina /></RutaProtegida>} />
          <Route path="mi-solicitud" element={<RutaProtegida permiso="mi_cuenta.ver"><MiSolicitudPagina /></RutaProtegida>} />
          <Route path="catalogo" element={<CatalogoPagina />} />
          <Route path="catalogo/:id" element={<ProductoDetallePagina />} />
          <Route path="carrito" element={<RutaProtegida permiso="carrito.usar"><CarritoPagina /></RutaProtegida>} />
          <Route path="checkout" element={<RutaProtegida permiso="carrito.usar"><CheckoutPagina /></RutaProtegida>} />
          <Route path="mis-pedidos" element={<RutaProtegida permiso="pedidos.propios"><MisPedidosPagina /></RutaProtegida>} />
          <Route path="mis-pedidos/:id" element={<RutaProtegida permiso="pedidos.propios"><PedidoDetallePagina /></RutaProtegida>} />
          <Route path="mis-pagos" element={<RutaProtegida permiso="pagos.propios"><MisPagosPagina /></RutaProtegida>} />
          <Route path="metodos-pago" element={<RutaProtegida permiso="metodos_pago.gestionar"><MetodosPagoPagina /></RutaProtegida>} />
          <Route path="planes-pago" element={<RutaProtegida permiso="planes_pago.propios"><PlanesPagoPagina /></RutaProtegida>} />
          <Route path="gestion/devoluciones" element={<RutaProtegida permiso="devoluciones.gestionar"><GestionDevolucionesPagina /></RutaProtegida>} />
          <Route path="gestion/entregas" element={<RutaProtegida permiso="entregas.gestionar"><GestionEntregasPagina /></RutaProtegida>} />
          <Route path="gestion/pagos" element={<RutaProtegida permiso="pagos.gestionar"><GestionPagosPagina /></RutaProtegida>} />
          <Route path="gestion/pedidos" element={<RutaProtegida permiso="pedidos.gestionar"><GestionPedidosPagina /></RutaProtegida>} />
          <Route path="gestion/pedidos/:id" element={<RutaProtegida permiso="pedidos.gestionar"><GestionPedidoDetallePagina /></RutaProtegida>} />
          <Route path="gestion/productos" element={<RutaProtegida permiso="productos.gestionar"><ProductosPagina /></RutaProtegida>} />
          <Route path="gestion/categorias" element={<RutaProtegida permiso="productos.gestionar"><CategoriasPagina /></RutaProtegida>} />
          <Route path="gestion/productores" element={<RutaProtegida permiso="productores.gestionar"><ProductoresPagina /></RutaProtegida>} />
          <Route path="gestion/comunidades" element={<RutaProtegida permiso="comunidades.gestionar"><ComunidadesPagina /></RutaProtegida>} />
          <Route path="productor/ficha" element={<RutaProtegida permiso="productor.ficha"><MiFichaPagina /></RutaProtegida>} />
          <Route path="productor" element={<RutaProtegida permiso="productor.panel"><PanelProductorPagina /></RutaProtegida>} />
          <Route path="productor/producciones/:id" element={<RutaProtegida permiso="producciones.propias"><ProduccionDetallePagina /></RutaProtegida>} />
          <Route path="productor/lotes" element={<RutaProtegida permiso="producciones.propias"><MisLotesPagina /></RutaProtegida>} />
          <Route path="productor/propuestas" element={<RutaProtegida permiso="producciones.propias"><PropuestasPagina /></RutaProtegida>} />
          <Route path="reportes" element={<RutaProtegida permiso="reportes.ver"><ReportesPagina /></RutaProtegida>} />
          <Route path="gestion/inventario" element={<RutaProtegida permiso="inventario.ver"><InventarioPagina /></RutaProtegida>} />
          <Route path="gestion/producciones" element={<RutaProtegida permiso="producciones.gestionar"><ProduccionesPagina /></RutaProtegida>} />
          <Route path="gestion/compras" element={<RutaProtegida permiso="compras.gestionar"><ComprasProductoresPagina /></RutaProtegida>} />
          <Route path="indicadores" element={<RutaProtegida permiso="indicadores.ver"><PanelPagina titulo="Indicadores" /></RutaProtegida>} />
          <Route path="productor/producciones" element={<RutaProtegida permiso="producciones.propias"><MisProduccionesPagina /></RutaProtegida>} />
          <Route path="productor/ventas" element={<RutaProtegida permiso="ventas_feder.propias"><MisVentasPagina /></RutaProtegida>} />
          <Route path="productor/indicadores" element={<RutaProtegida permiso="indicadores.propios"><MisVentasPagina soloIndicadores /></RutaProtegida>} />
          <Route path="mis-devoluciones" element={<RutaProtegida permiso="devoluciones.propias"><MisDevolucionesPagina /></RutaProtegida>} />
          <Route path="mis-facturas" element={<RutaProtegida permiso="facturas.propias"><MisFacturasPagina /></RutaProtegida>} />
          <Route path="mis-facturas/:id" element={<RutaProtegida permiso="facturas.propias"><FacturaPagina /></RutaProtegida>} />
          <Route path="notificaciones" element={<RutaProtegida permiso="notificaciones.ver"><NotificacionesPagina /></RutaProtegida>} />
          <Route path="panel" element={<RutaProtegida permiso="dashboard.ver"><PanelPagina titulo="Panel" /></RutaProtegida>} />
          <Route path="operaciones" element={<RutaProtegida permiso="operaciones.panel"><PanelOperacionesPagina /></RutaProtegida>} />
          <Route path="administracion" element={<RutaProtegida permiso="administracion.panel"><PanelAdministracionPagina /></RutaProtegida>} />
          <Route path="admin/usuarios" element={<RutaProtegida permiso="usuarios.gestionar"><UsuariosPagina /></RutaProtegida>} />
          <Route path="admin/permisos" element={<RutaProtegida permiso="permisos.gestionar"><PermisosPagina /></RutaProtegida>} />
          <Route path="admin/bitacora" element={<RutaProtegida permiso="bitacora.ver"><BitacoraPagina /></RutaProtegida>} />
          <Route path="admin/accesos" element={<RutaProtegida permiso="accesos.ver"><AccesosPagina /></RutaProtegida>} />
          {/* La SPA compilada se publica en …/app/: quien abra esa dirección va al inicio. */}
          <Route path="app/*" element={<Navigate to="/" replace />} />
          <Route path="*" element={<RutaDinamica />} />
        </Route>
      </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
