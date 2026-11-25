import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ShoppingBasket } from 'lucide-react'
import { comercioApi, EVENTO_CARRITO } from '../../api/comercioApi'

export default function AccesoCarrito() {
  const [cantidad, setCantidad] = useState(null)
  useEffect(() => {
    let vigente = true
    let revision = 0
    const consultar = async () => {
      const version = ++revision
      try {
        const carrito = await comercioApi.carrito()
        if (vigente && version === revision) setCantidad(carrito.cantidad_productos)
      } catch { if (vigente && version === revision) setCantidad(null) }
    }
    const actualizar = ({ detail }) => {
      if (detail == null) { consultar(); return }
      revision++
      setCantidad(detail)
    }
    consultar()
    window.addEventListener(EVENTO_CARRITO, actualizar)
    return () => { vigente = false; window.removeEventListener(EVENTO_CARRITO, actualizar) }
  }, [])
  return <Link to="/carrito" className="encabezado__carrito"
    aria-label={cantidad == null ? 'Carrito' : 'Carrito, ' + cantidad + (cantidad === 1 ? ' producto' : ' productos')}>
    <ShoppingBasket size={19} aria-hidden="true" /> Carrito {cantidad !== null && <span>{cantidad}</span>}
  </Link>
}
