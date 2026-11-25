import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ShoppingBasket, Trash2 } from 'lucide-react'
import { comercioApi } from '../../api/comercioApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import Tarjeta from '../../components/ui/Tarjeta'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import EstadoVacio from '../../components/plantillas/EstadoVacio'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { cantidadConUnidad, UNIDADES } from '../../utils/etiquetas'
import { formatearMoneda } from '../../utils/formato'
import './CarritoPagina.css'

export default function CarritoPagina() {
  const { tienePermiso } = useSesion()
  const puedeVerPlanes = tienePermiso('planes_pago.propios')
  const carrito = useCargaApi(() => comercioApi.carrito(), [])
  const planes = useCargaApi(() => puedeVerPlanes ? comercioApi.planesPago() : Promise.resolve(null), [puedeVerPlanes])
  const tienePlanActivo = Boolean(planes.datos?.planes?.some((p) => p.estado === 'ACTIVO'))
  const [error, setError] = useState(null)
  const [ocupado, setOcupado] = useState(null)
  const navegar = useNavigate()

  const ejecutar = async (productoId, accion) => {
    setOcupado(productoId)
    setError(null)
    try {
      carrito.reemplazar(await accion())
    } catch (falla) {
      setError(Object.values(falla.errores || {})[0]?.[0] || falla.mensaje)
    } finally {
      setOcupado(null)
    }
  }

  const datos = carrito.datos
  if (!datos && (carrito.cargando || carrito.error)) {
    return <section className="carrito">
      <EncabezadoPagina titulo="Mi carrito" />
      {carrito.error ? <Alerta tipo="error">{carrito.error} <Boton onClick={carrito.recargar}>Reintentar</Boton></Alerta>
        : <Cargando texto="Cargando tu carrito…" />}
    </section>
  }
  if (!datos || datos.items.length === 0) {
    return (
      <section className="carrito">
        <EncabezadoPagina titulo="Mi carrito" />
        <EstadoVacio
          titulo="Tu carrito está vacío"
          mensaje="Explora el catálogo y agrega los productos de nuestras comunidades."
          icono={ShoppingBasket}
          accion={<Boton como={Link} to="/catalogo">Ver catálogo</Boton>}
        />
      </section>
    )
  }

  const puedeContinuar = datos.todo_con_stock || datos.todo_admite_encargo

  return (
    <div className="carrito">
      <EncabezadoPagina
        titulo="Mi carrito"
        descripcion="Revisa cantidades y stock antes de confirmar la compra."
      />
      {(error || carrito.error) && <Alerta tipo="error">{error || carrito.error}</Alerta>}
      {tienePlanActivo && (
        <Alerta tipo="info">
          Lo que tienes en el carrito será una compra nueva: no se suma a las cuotas que estás pagando.
          Tus cuotas se pagan desde <Link to="/planes-pago">Planes de pago</Link>.
        </Alerta>
      )}
      {!datos.todo_con_stock && (
        <Alerta tipo="aviso">
          {datos.todo_admite_encargo
            ? 'Algunos productos no tienen stock suficiente: podrás confirmarlos como encargo.'
            : 'Algunos productos no tienen stock suficiente y no admiten encargo. Reduce la cantidad o quítalos para continuar.'}
        </Alerta>
      )}

      <div className="carrito__contenido">
        <ul className="carrito__lista">
          {datos.items.map((item) => (
            <ItemCarrito key={item.producto_id} item={item} ocupado={ocupado === item.producto_id}
              alCambiar={(cantidad) => ejecutar(item.producto_id, () => comercioApi.cambiarCantidad(item.producto_id, cantidad))}
              alQuitar={() => ejecutar(item.producto_id, () => comercioApi.quitarDelCarrito(item.producto_id))} />
          ))}
        </ul>

        <Tarjeta titulo="Resumen" className="carrito__resumen">
          <dl className="carrito__totales">
            <dt>Productos</dt><dd>{datos.cantidad_productos}</dd>
            <dt>Subtotal</dt><dd>{formatearMoneda(datos.subtotal)}</dd>
          </dl>
          <p className="carrito__nota">El costo de envío se calcula en el siguiente paso.</p>
          <Boton bloque disabled={!puedeContinuar} onClick={() => navegar('/checkout')}>Continuar con la compra</Boton>
        </Tarjeta>
      </div>
    </div>
  )
}

function ItemCarrito({ item, ocupado, alCambiar, alQuitar }) {
  const [cantidad, setCantidad] = useState(String(item.cantidad))
  const porUnidad = item.unidad_medida === 'UNIDAD'

  const confirmarCantidad = () => {
    const numero = Number(cantidad)
    if (!numero || numero <= 0 || numero === item.cantidad) {
      setCantidad(String(item.cantidad))
      return
    }
    alCambiar(numero)
  }

  return (
    <li className="item-carrito">
      <div className="item-carrito__datos">
        <Link to={`/catalogo/${item.producto_id}`} className="item-carrito__nombre">{item.nombre}</Link>
        <span className="item-carrito__detalle">{item.presentacion} · {formatearMoneda(item.precio)} / {UNIDADES[item.unidad_medida]?.larga}</span>
        {!item.alcanza_stock && (
          <span className="item-carrito__aviso">
            Stock disponible: {cantidadConUnidad(item.stock, item.unidad_medida)}{item.permite_encargo ? ' · se puede encargar' : ''}
          </span>
        )}
      </div>
      <label className="solo-lector" htmlFor={`cantidad-${item.producto_id}`}>Cantidad de {item.nombre}</label>
      <input id={`cantidad-${item.producto_id}`} className="item-carrito__cantidad" type="number" min={porUnidad ? 1 : 0.1}
        step={porUnidad ? 1 : 0.1} max="999" value={cantidad} disabled={ocupado}
        onChange={(e) => setCantidad(e.target.value)} onBlur={confirmarCantidad}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} />
      <span className="item-carrito__subtotal">{formatearMoneda(item.subtotal)}</span>
      <button type="button" className="item-carrito__quitar" onClick={alQuitar} disabled={ocupado} aria-label={`Quitar ${item.nombre}`}>
        <Trash2 size={18} aria-hidden="true" />
      </button>
    </li>
  )
}
